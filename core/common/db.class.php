<?php
/**
 * 数据库基础类
 */

class DataBaseConn {
    public $link = null;
    public $db_host = null;
    public $db_port = 3306;
    public $db_user = null;
    public $db_pass = null;
    public $db_database = null;
    public $pconnect = false;   //持久链接
    public $charset = "utf8";
    public $wait_timeout = 0;

    /**
     * 构造函数
     */
    function __construct(){
    }

    /**
     * 初始化链接参数
     */
    public function initConnect($db_host, $db_port, $db_user, $db_pass, $db_database='', $charset=''){
        $this->db_host = $db_host;
        $this->db_port = $db_port;
        $this->db_user = $db_user;
        $this->db_pass = $db_pass;
        $this->db_database = $db_database;
        $this->chatset = $charset;
    }

    public function setWaitTimeout($wait_timeout){
        $this->wait_timeout = $wait_timeout;
    }

    public function connectDB(){
        if($this->pconnect){
            $this->link = mysqli_connect("p:".$this->db_host, $this->db_user, $this->db_pass, $this->db_database, $this->db_port);
        } else {
            $this->link = mysqli_connect($this->db_host, $this->db_user, $this->db_pass, $this->db_database, $this->db_port);
        }

        if(!$this->link){
            $this->halt("Connect to MySQL server failed " . mysqli_error($this->link));
        }

        //设置字符集
        if(!empty($this->charset)){
            mysqli_set_charset($this->link, $this->charset);
        }

        if($this->wait_timeout > 0){
            $this->query("SET @@session.wait_timeout={$this->wait_timeout}");
        }
    }

    private function autoConnect(){
        if(!$this->link || !$this->ping){
            $this->connectDB();
        }
    }

    private function ping(){
        if(mysqli_ping($this->link)){
            return true;
        }
        return false;
    }

    //关闭数据库
    public function close(){
        $ret = true;
        if($this->link){
            $ret = mysqli_close($this->link);
        }
        $this->link = null;
        return $ret;
    }

    public function version(){
        $this->autoConnect();
        return mysqli_get_server_info($this->link);
    }

    public function selectDB($db_database){
        $this->autoConnect();
        return mysqli_select_db($this->link, trim($db_database));
    }

    private function halt($message='', $sql=''){
        $this->logError($sql, $message);
        throw new Exception($message);
    }

    //记录查询日志
    private function logQuery($sql, $query_time){
        if($query_time > 1){
            file_put_contents("/home/log/mysql_query.log", "[".date('Y-m-d H:i:s')."] | Host:{$this->db_host} | SQL:{$sql} | TotalTime:{$query_time}\n", FILE_APPEND);
        }
    }

    //记录错误日志
    private function logError($sql, $err){
        file_put_contents("/home/log/mysql_err.log", "[".date('Y-m-d H:i:s')."] | Host:{$this->db_host} | SQL:{$sql} | Error:{$err}\n", FILE_APPEND);
    }

    //记录SQL注入
    private function logQueryInjection($sql, $desc){
        file_put_contents("/home/log/mysql_injection.log", "[".date('Y-m-d H:i:s')."] | Host:{$this->db_host} | SQL Injection:{$sql} | Description:$desc\n", FILE_APPEND);
    }

    /**
     * 执行MySQL语句
     */
    public function query($sql){
        //合法性检测
        $sql_check = str_replace("\\\\", "", $sql);
        $sql_check = str_replace("\\'", "", $sql_check);
        if(substr_count($sql_check, "'") % 2 == 1){
            $this->logQueryInjection($sql, 'SQL语句有未闭合的单引号');
            if(preg_match("/drop\s+(?:table|database)/i", $sql)){
                $this->logQueryInjection($sql, 'DROP语句注入');
            }
        }

        //执行
        try{
            $this->autoConnect();
        } catch(Exception $e){
            $this->logError($sql, $e->getMessage());
        }

        $begin_time = time();
        $query = mysqli_query($this->link, $sql);
        $end_time = time();
        $query_time = $end_time - $begin_time;

        $this->logQuery($sql, $query_time);
        if($this->error())
        return $query;
    }

    /**
     * 查询相关
     */
    //去除反斜杠
    public function checkInput($value){
        if(get_magic_quotes_gpc()){
            $value = stripslashes($value);
        }
        $value = $this->readEscapeString($value);
        return $value;
    }

    //从数据库选取一个值
    public function getOne($sql){
        $query = $this->query($sql);
        $row = $this->fecthRow($query);
        $this->freeResult($query);
        return $row[0];
    }

    //从数据库选取一行
    public function getRow($sql){
        $query = $this->query($sql);
        $row = $this->fecthArray($query);
        $this->freeResult($query);
        return $row;
    }

    //从数据库选取所有行
    public function getAll($sql){
        $query = $this->query($sql);
        $result = array();
        while($row = $this->fecth_array($query)){
            $result[] = $row;
        }
        $this->freeResult($query);
        return $result;
    }

    /**
     * mysqli函数封装
     */
    //字符串过滤
    private function realEscapeString($sql){
        $this->autoConnect();
        return mysqli_real_escape_string($this->link, $sql);
    }

    //执行MySQL语句后影响的行数
    public function affectedRows(){
        return mysql_affected_rows($this->link);
    }

    //当前插入记录的id
    public function insertId(){
        return mysqli_insert_id($this->link);
    }

    //释放记录对象
    private function freeResult($query){
        if(is_bool($query)){
            return;
        }
        return mysqli_free_result($query);
    }

    private function fetchRow($query){
        return mysqli_fetch_row($query);
    }

    private function fecthArray($query, $result_type=MYSQLI_ASSOC){
        $this->autoConnect();
        return mysqli_fetch_array($query, $result_type);
    }

    /**
     * 事务相关
     */
    //开始事务
    public function beginTransaction(){
        $this->query("SET autocommit=0;");
    }
    //提交事务
    public function commit(){
        $this->query("commit;");
        $this->query("SET autocommit=1;");
    }
    //回滚事务
    public function rollBack(){
        $this->query("rollback;");
    }
}

$user_account_read = new DataBaseConn();
$user_account_read->initConnect(
    $config['UserAccountRead']['host'],
    $config['UserAccountRead']['port'],
    $config['UserAccountRead']['user'],
    $config['UserAccountRead']['passwd'],
    $config['UserAccountRead']['database'],
    $config['UserAccountRead']['charset']
);
