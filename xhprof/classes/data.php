<?php

namespace ay\xhprof;

use PDO;

class data
{
    /**
     * @var \PDO
     */
    private $db;

    /**
     * @var \PDOStatement
     */
    private $fetchPlayerStmt;
    /**
     * @var \PDOStatement
     */
    private $insertPlayerStmt;

    public function __construct(PDO $db)
    {
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, FALSE);
        }

        $this->db	= $db;
    }

    public function get($id)
    {
        $request = $this->db->query(sprintf("
            SELECT
                `r1`.`id`,
                UNIX_TIMESTAMP(`r1`.`request_timestamp`) `request_timestamp`,
                `rh1`.`id` `host_id`,
                `rh1`.`host`,
                `ru1`.`id` `uri_id`,
                `ru1`.`uri`
            FROM
                `requests` `r1`
            INNER JOIN
                `request_hosts` `rh1`
            ON
                `rh1`.`id` = `r1`.`request_host_id`
            INNER JOIN
                `request_uris` `ru1`
            ON
                `ru1`.`id` = `r1`.`request_uri_id`
            WHERE
                `r1`.`id` = %d
            LIMIT 1;", $id))->fetch(PDO::FETCH_ASSOC);

        if(!$request) {
            return FALSE;
        }

       $request['callstack'] = $this->db->query(sprintf("
            SELECT
                `c1`.`ct`,
                `c1`.`wt`,
                `c1`.`cpu`,
                `c1`.`mu`,
                `c1`.`pmu`,
                `p1`.`id` `caller_id`,
                `p1`.`name` `caller`,
                `p2`.`id` `callee_id`,
                `p2`.`name` `callee`
            FROM
                `calls` `c1`
            LEFT JOIN
                `players` `p1`
            ON
                `p1`.`id` = `c1`.`caller_id`
            INNER JOIN
                `players` `p2`
            ON
                `p2`.`id` = `c1`.`callee_id`
            WHERE
                `c1`.`request_id` = %d
            ORDER BY
                `c1`.`id` DESC;
            ", $request['id']))->fetchAll(PDO::FETCH_ASSOC);

        // The data input will never change. Therefore,
        // I arrange all the values manually.

        if($request['callstack'][0]['caller'] !== NULL) {
            throw new DataException('Data order does not follow the suit. Mother entry is expected to be the first in the callstack.');
        }

        $request['total']		= array
        (
            'ct'	=> 0,
            'wt'	=> $request['callstack'][0]['wt'],
            'cpu'	=> $request['callstack'][0]['cpu'],
            'mu'	=> $request['callstack'][0]['mu'],
            'pmu'	=> $request['callstack'][0]['pmu']
        );

        // Format the data.
        foreach($request['callstack'] as $k => $e) {
            $request['total']['ct']		+= $e['ct'];

            $request['callstack'][$k] = array
            (
                'caller_id'	=> $e['caller_id'],
                'caller'	=> $e['caller'],
                'callee_id'	=> $e['callee_id'],
                'callee'	=> $e['callee'],
                'metrics'	=> array
                (
                    'ct'	=> $e['ct'],
                    'wt'	=> $e['wt'],
                    'cpu'	=> $e['cpu'],
                    'mu'	=> $e['mu'],
                    'pmu'	=> $e['pmu']
                )
            );
        }

        return $request;
    }

    /**
     * @param	array	$xhprof_data	The raw XHProf data.
     */
    public function save(array $xhprof_data)
    {
        if(!isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'])) {
            throw new DataException('XHProf.io cannot function in a server environment that does not define REQUEST_METHOD, HTTP_HOST or REQUEST_URI.');
        }

        $query = sprintf("
              SELECT
                (SELECT `id` FROM `request_methods` WHERE `method` = %s LIMIT 1) as 'method_id',
                (SELECT `id` FROM `request_hosts` WHERE `host` = %s LIMIT 1) as 'host_id',
                (SELECT `id` FROM `request_uris` WHERE `uri` = %s LIMIT 1) as 'uri_id';",
                $this->db->quote($_SERVER['REQUEST_METHOD']),
                $this->db->quote($_SERVER['HTTP_HOST']),
                $this->db->quote($_SERVER['REQUEST_URI'])
        );
        $request = $this->db->query($query)->fetch(PDO::FETCH_ASSOC);

        if(!isset($request['method_id'])) {
            $this->db->query(sprintf("INSERT INTO `request_methods` SET `method` = %s;", $this->db->quote($_SERVER['REQUEST_METHOD'])));

            $request['method_id']	= $this->db->lastInsertId();
        }

        if(!isset($request['host_id'])) {
            $this->db->query(sprintf("INSERT INTO `request_hosts` SET `host` = %s;", $this->db->quote($_SERVER['HTTP_HOST'])));

            $request['host_id']		= $this->db->lastInsertId();
        }

        if(!isset($request['uri_id'])) {
            $this->db->query(sprintf("INSERT INTO `request_uris` SET `uri` = %s;", $this->db->quote($_SERVER['REQUEST_URI'])));

            $request['uri_id']		= $this->db->lastInsertId();
        }

        $this->db->query(
            sprintf(
                "INSERT INTO `requests` SET `request_host_id` = %d, `request_uri_id` = %d, `request_method_id` = %d, `https` = %d;",
                $request['host_id'],
                $request['uri_id'],
                $request['method_id'],
                empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off' ? 0 : 1
            )
        );

        $request_id	= $this->db->lastInsertId();

        $updateRequestStmt       = $this->db->prepare("UPDATE `requests` SET `request_caller_id` = :request_caller_id WHERE `id` = :request_id;");
        $this->fetchPlayerStmt   = $this->db->prepare("SELECT `id` FROM `players` WHERE `name` = :name LIMIT 1;");
        $this->insertPlayerStmt  = $this->db->prepare("INSERT INTO `players` SET `name` = :name;");

        // collect all data for a batch insert
        $callBatch = array();
        $rootCall = null;
        foreach($xhprof_data as $call => $data) {
            $callRow = array();
            $callRow['request_id'] = (int) $request_id;
            $callRow['ct'] = (int) $data['ct'];
            $callRow['wt'] = (int) $data['wt'];
            $callRow['cpu'] = (int) $data['cpu'];
            $callRow['mu'] = (int) $data['mu'];
            $callRow['pmu'] = (int) $data['pmu'];

            $call	= explode('==>', $call);
            $nbCall = count($call);
            if($nbCall == 1) {
                // root call
                $callee_id = $this->fetchOrCreatePlayerId($call[0]);

                $callRow['caller_id'] = null;
                $callRow['callee_id'] = (int) $callee_id;

                $rootCall = $callRow;
            } else {
                // nested calls
                $caller_id = $this->fetchOrCreatePlayerId($call[0]);
                $callee_id = $this->fetchOrCreatePlayerId($call[1]);

                $callRow['caller_id'] = (int) $caller_id;
                $callRow['callee_id'] = (int) $callee_id;

                // add the rows in reverse order, so the graph will show the function calls in the order they were called
                array_unshift($callBatch, $callRow);
            }
        }

        $this->fetchPlayerStmt = null;
        $this->insertPlayerStmt = null;

        // insert all the data in bigger batches, for performance reasons
        foreach(array_chunk($callBatch, 5000) as $batch) {
            $this->batchInsertCalls($batch);
        }

        // mother call has to be the last row inserted
        $call_id = $this->insertCall($rootCall, true);
        $updateRequestStmt->execute(array('request_caller_id' => $call_id, 'request_id' => $request_id));

        return $request_id;
    }

    private function batchInsertCalls(array $calls)
    {
        if (empty($calls)) {
            return;
        }
        $qry = "INSERT INTO `calls`\n";

        $builtColNames = true;
        foreach($calls as $call) {
            if ($builtColNames) {
                $qry .= '(';
                foreach($call as $colName => $val) {
                    $qry .= $colName .',';
                }
                $qry = rtrim($qry, ',');
                $qry .= ')'. "\n";
                $qry .= ' VALUES '. "\n";
            }
            $builtColNames = false;

            $qry .= '(';
            foreach($call as $val) {
                $qry .= $this->db->quote($val) .",";
            }
            $qry = rtrim($qry, ',');
            $qry .= '),';
        }
        $qry = rtrim($qry, ',');

        $this->db->query($qry);
    }

    private function insertCall(array $call, $requestInsertId = false)
    {
        $qry = "INSERT INTO `calls` SET ";

        foreach($call as $colName => $val) {
            $qry .= $colName ."=". $this->db->quote($val) .",";
        }
        $qry = rtrim($qry, ',');

        $this->db->query($qry);

        if ($requestInsertId) {
            return $this->db->lastInsertId();
        }
    }

    private function fetchOrCreatePlayerId($name)
    {
        static $playerIdCache = array();

        if (isset($playerIdCache[$name])) {
            return $playerIdCache[$name];
        }

        $this->fetchPlayerStmt->execute(array('name' => $name));
        $playerId = $this->fetchPlayerStmt->fetch(PDO::FETCH_COLUMN);
        $this->fetchPlayerStmt->closeCursor();

        if(!$playerId) {
            $this->insertPlayerStmt->execute(array('name' => $name));
            $playerId = $this->db->lastInsertId();
            $this->insertPlayerStmt->closeCursor();
        }

        $playerIdCache[$name] = $playerId;
        return $playerIdCache[$name];
    }

    public function getHosts(array $query = NULL)
    {
        $this->aggregateRequestData($query, array('datetime_from', 'datetime_to', 'host', 'host_id'));

        $data				= array();

        $data['discrete']	= $this->db->query("
            SELECT
                `host_id`,
                `host`,

                COUNT(`request_id`) `request_count`,

                AVG(`wt`) `wt`,
                AVG(`cpu`) `cpu`,
                AVG(`mu`) `mu`,
                AVG(`pmu`) `pmu`
            FROM
                `temporary_request_data`
            GROUP BY
                `host_id`
            ORDER BY
                `host`;
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    public function getUris(array $query = NULL)
    {
        $this->aggregateRequestData($query);

        $data				= array();

        $data['discrete']	= $this->db->query("
            SELECT
                `host_id`,
                `host`,
                `uri_id`,
                `uri`,

                COUNT(`request_id`) `request_count`,

                AVG(`wt`) `wt`,
                AVG(`cpu`) `cpu`,
                AVG(`mu`) `mu`,
                AVG(`pmu`) `pmu`
            FROM
                `temporary_request_data`
            GROUP BY
                `uri_id`
            ORDER BY
                `host`;
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    public function getRequests(array $query = NULL)
    {
        $this->aggregateRequestData($query);

        $data				= array();

        $data['discrete']	= $this->db->query("SELECT * FROM `temporary_request_data`;")->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    public function getMetricsSummary()
    {
        $data	= $this->db->query("
            SELECT
                COUNT(`request_id`),

                MIN(`wt`),
                MAX(`wt`),
                AVG(`wt`),

                MIN(`cpu`),
                MAX(`cpu`),
                AVG(`cpu`),

                MIN(`mu`),
                MAX(`mu`),
                AVG(`mu`),

                MIN(`pmu`),
                MAX(`pmu`),
                AVG(`pmu`)
            FROM
                `temporary_request_data`;
        ")
            ->fetch(PDO::FETCH_NUM);

        if(!$data) {
            throw new DataException('Cannot aggregate non-existing metrics.');
        }

        $return	= array
        (
            'request_count'	=> $data[0],
            'wt'			=> array_combine(array('min', 'max', 'avg'), array_slice($data, 1, 3)),
            'cpu'			=> array_combine(array('min', 'max', 'avg'), array_slice($data, 4, 3)),
            'mu'			=> array_combine(array('min', 'max', 'avg'), array_slice($data, 7, 3)),
            'pmu'			=> array_combine(array('min', 'max', 'avg'), array_slice($data, 10, 3))
        );

        // I've tried so much more sophisticated approaches to calculate the Nth percentile,
        // though with large datasets that's virtually impossible (+30s). See b40d38b commit.

        $percentile_offset	= floor($return['request_count']*.95);

        foreach(array('wt', 'cpu', 'mu', 'pmu') as $column) {
            // I've excluded median on purpose, because it is relatively costly calculation, arguably of any value.
            $return[$column]['95th']	= $this->db->query("SELECT `{$column}` FROM `temporary_request_data` ORDER BY `{$column}` ASC LIMIT {$percentile_offset}, 1;")->fetch(PDO::FETCH_COLUMN);
            $return[$column]['mode']	= $this->db->query("SELECT `{$column}` FROM `temporary_request_data` GROUP BY `{$column}` ORDER BY COUNT(`{$column}`) DESC LIMIT 1;")->fetch(PDO::FETCH_COLUMN);
        }

        return $return;
    }

    private function buildQuery(array $query = NULL, array $whitelist = array())
    {
        if(empty($whitelist)) {
            $whitelist	= array('datetime_from', 'datetime_to', 'host', 'host_id', 'uri', 'uri_id', 'request_id');
        }

        $return		= array
        (
            'where'			=> ''
        );

        if($query === NULL) {
            return $return;
        }

        $whitelist	= array_merge($whitelist, array('dataset_size'));

        if(count(array_diff_key($query, array_flip($whitelist)))) {
            throw new DataException('Not supported filter parameters cannot be present in the query.');
        }

        // build WHERE query
        if(isset($query['request_id'])) {
            $return['where']	.= sprintf(' AND `r1`.`id` = %d ', $query['request_id']);
        }

        if(isset($query['uri'], $query['uri_id'])) {
            throw new DataException('Numerical index overwrites the string matching. Unset either to prevent unexpected results.');
        } else if(isset($query['uri'])) {
            $return['where']	.= sprintf(' AND `ru1`.`uri` LIKE %s ', $this->db->quote($query['uri']));
        } else if(isset($query['uri_id'])) {
            $return['where']	.= sprintf(' AND `r1`.`request_uri_id` = %d ', $query['uri_id']);
        }

        if(isset($query['host'], $query['host_id'])) {
            throw new DataException('Numerical index overwrites the string matching. Unset either to prevent unexpected results.');
        } else if(isset($query['host'])) {
            $return['where']	.= sprintf(' AND `rh1`.`host` LIKE %s ', $this->db->quote($query['host']));
        } else if(isset($query['host_id'])) {
            $return['where']	.= sprintf(' AND `r1`.`request_host_id` = %d ', $query['host_id']);
        }

        if(isset($query['datetime_from'])) {
            $return['where']	.= sprintf(' AND `r1`.`request_timestamp` > %s ', $this->db->quote($query['datetime_from']));
        }

        if(isset($query['datetime_to'])) {
            $return['where']	.= sprintf(' AND `r1`.`request_timestamp` < %s ', $this->db->quote($query['datetime_to']));
        }

        return $return;
    }

    /**
     * This method creates a temporary table. The table is populated
     * with the data necessary to analyze requests matching the query.
     *
     * @param	array	$query User-input used to generate the query WHERE and LIMIT clause.
     * @param	array	$whitelist	Is required whenever any of the filters (currently, self::getHosts only) does not support either of the standard $query parameters.
     * @todo add BTREE indexes
     */
    private function aggregateRequestData($query, array $whitelist = array())
    {
        $query['dataset_size']	= empty($query['dataset_size']) ? 1000 : $query['dataset_size'];

        $sql_query				= $this->buildQuery($query, $whitelist);

        $data_query = sprintf("
            SELECT
                r1.id request_id,
                UNIX_TIMESTAMP(r1.request_timestamp) request_timestamp,

                rh1.id host_id,
                rh1.host,

                ru1.id uri_id,
                ru1.uri,

                rm1.method request_method,

                c1.wt,
                c1.cpu,
                c1.mu,
                c1.pmu
            FROM
                requests r1
            INNER JOIN
                request_hosts rh1
            ON
                rh1.id = r1.request_host_id
            INNER JOIN
                request_uris ru1
            ON
                ru1.id = r1.request_uri_id
            INNER JOIN
                request_methods rm1
            ON
                rm1.id = r1.request_method_id
            INNER JOIN
                calls c1
            ON
                c1.id = r1.request_caller_id
            WHERE
                1=1 %s
            ORDER BY
                request_id DESC
            LIMIT
                %d
        ", $sql_query['where'], $query['dataset_size']);

        $this->db->query("CREATE TEMPORARY TABLE `temporary_request_data` ENGINE=". TMP_TABLE_ENGINE ." AS ({$data_query});");
    }
}

class DataException extends \Exception {}
