<?php
namespace ay\xhprof;

class model
{
    private $request;

    public function __construct($request)
    {
        $defined_functions		= get_defined_functions();

        foreach($request['callstack'] as &$call) {
            $call['internal']	= in_array($call['callee'], $defined_functions['internal']);
            $call['group']		= $this->getGroup($call);

            unset($call);
        }

        $this->request	= $request;
    }

    public function getCallstack()
    {
        return $this->request['callstack'];
    }

    /**
     * The raw data contains inclusive metrics of a function for each
     * unique parent function it is called from. The total inclusive metrics
     * for a function is therefore the sum of inclusive metrics for the
     * function across all parents.
     *
     * @author	Gajus Kuizinas <g.kuizinas@anuary.com>
     */
    private function computeInclusiveMetrics()
    {
        $functions	= array();

        foreach($this->request['callstack'] as $call) {
            $caller	= $call['caller_id'];

            unset($call['caller_id'], $call['caller']);

            if(!isset($functions[$call['callee_id']])) {
                $call['callers']				= array($caller);

                $functions[$call['callee_id']]	= $call;

                continue;
            }

            $functions[$call['callee_id']]['callers'][]	= $caller;

            foreach($call['metrics'] as $k => $v) {
                $functions[$call['callee_id']]['metrics'][$k]	+= $v;
            }
        }

        return array_values($functions);
    }

    /**
     * Analyze the raw data, and compute a per-function (flat)
     * inclusive and exclusive metrics.
     *
     * @author	Gajus Kuizinas <g.kuizinas@anuary.com>
     */
    public function getAggregatedStack()
    {
        $functions	= $this->computeInclusiveMetrics();

        foreach($functions as &$function) {
            $function['metrics']	= array
            (
                'ct'		=> $function['metrics']['ct'],
                'inclusive'	=> $function['metrics'],
                'exclusive'	=> $function['metrics']
            );

            unset($function['metrics']['inclusive']['ct'], $function['metrics']['exclusive']['ct']);

            foreach($this->request['callstack'] as $call) {
                if($function['callee_id'] == $call['caller_id']) {
                    foreach(array('wt', 'cpu', 'mu', 'pmu') as $k) {
                        $function['metrics']['exclusive'][$k]	-= $call['metrics'][$k];
                    }
                }
            }

            unset($function);
        }

        return $functions;
    }

    /**
     * @return	array	$callee_id	Aggregated metrics for the $callee_id, callers and children.
     */
    public function getFamily($callee_id)
    {
        $aggregated_stack	= $this->getAggregatedStack();

        foreach($aggregated_stack as $call) {
            if($call['callee_id'] == $callee_id) {
                $callee	= $call;

                break;
            }
        }

        if(!isset($callee)) {
            return FALSE;
        }

        // find callers & children
        $callers			= array();
        $children			= array();

        foreach($aggregated_stack as $function) {
            if(in_array($function['callee_id'], $callee['callers'])) {
                $callers[]	= $function;
            } else if(in_array($callee['callee_id'], $function['callers'])) {
                $children[]	= $function;
            }
        }

        return array
        (
            'callers'	=> $callers,
            'callee'	=> $callee,
            'children'	=> $children
        );
    }
    
    private function getPathToParent($node, $callstack) {
        $parents = array();
        
        $cur = $node;
        while($cur && !empty($cur['caller_id']))
        {
            // we reached a node which already knows his parents
            if (!empty($cur['parents'])) {
                foreach(array_reverse($cur['parents']) as $parent) {
                    $parents[] = $parent;
                }
                break;
            }
            $parents[]	= $cur['caller_id'];
            
            // we reached the top of the stack
            if ($cur['caller_id'] == 'xhprof') {
                break;
            }
            
            $cur = $this->findNodeInStack($cur['caller_id'], $callstack);
        }

        // child-parent relations are modelled using the same prefix in parent-path
        // reverse the path so all node start at the same main node
        return array_reverse($parents);
    }
    
    private function findNodeInStack($calleeId, $callstack) {
        foreach($callstack as $el) {
            if ($el['callee_id'] == $calleeId) {
                return $el;
            }
        }
    }

    public function assignUID()
    {
        $callstack	= $this->request['callstack'];
        $callstack[0]['caller_id']	= 'xhprof';
        
        foreach($callstack as &$c) {
            $c['parents']			= $this->getPathToParent($c, $callstack);
        }

        return array_map(function ($e) {
            // UID is used to determine the child-parent relation in an exclusive callgraph.
            $e['uid']	= implode('_', $e['parents']);

            // Unless we were looking to build an inclusive callgraph, this data is redundant. We are not.
            unset($e['parents']);

            return $e;
        }, $callstack);
    }

    public function getGroupedStack()
    {
        $aggregated_stack	= $this->getAggregatedStack();

        foreach($aggregated_stack as $e) {
            // exclude "main()"
            if(empty($e['group'])) {
                continue;
            }

            if(!isset($groups[$e['group']['index']])) {
                $groups[$e['group']['index']]	= array
                (
                    'index'		=> $e['group']['index'],
                    'name'		=> $e['group']['name'],
                    'metrics'	=> $e['metrics']['exclusive'] + array('ct' => 0)
                );
            } else {
                $groups[$e['group']['index']]['metrics']['ct']	+= $e['metrics']['ct'];
                $groups[$e['group']['index']]['metrics']['wt']	+= $e['metrics']['exclusive']['wt'];
                $groups[$e['group']['index']]['metrics']['cpu']	+= $e['metrics']['exclusive']['cpu'];
                $groups[$e['group']['index']]['metrics']['mu']	+= $e['metrics']['exclusive']['mu'];
                $groups[$e['group']['index']]['metrics']['pmu']	+= $e['metrics']['exclusive']['pmu'];
            }
        }

        return $groups;
    }

    private $groups	= array();

    private function getGroup($e)
    {
        if(empty($e['caller'])) {
            return;
        }

        if(!isset($e['internal'], $e['callee'])) {
            throw new ModelException('Data format does not comply with the XHProf conventions.');
        }

        if($e['internal']) {
            $group	= 'internal';
        } else {
            $group	= explode('::', $e['callee'], 2);

            $group	= count($group) === 1 ? 'user-defined function' : $group[0];
        }

        if(!isset($this->groups[$group])) {
            $this->groups[$group]	= array
            (
                'index'	=> count($this->groups)+1,
                'name'	=> $group
            );
        }

        return $this->groups[$group];
    }
}

class ModelException extends \Exception {}
