<?php
/**
 * This file is part of ProFTPd Admin
 *
 * @package ProFTPd-Admin
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 *
 * @copyright Lex Brugman <lex_brugman@users.sourceforge.net>
 * @copyright Christian Beer <djangofett@gmx.net>
 * @copyright Ricardo Padilha <ricardo@droboports.com>
 *
 */

// hash_pbkdf2 implementation for 5.3 <= PHP < 5.5
if ($cfg['passwd_encryption'] == "pbkdf2") {
  require "hash_pbkdf2_compat.php";
} elseif ($cfg['passwd_encryption'] == "crypt") {
  require "unix_crypt.php";
}

include_once "ez_sql_core.php";
if (!isset($cfg['db_type']) || $cfg['db_type'] == "mysqli") {
  include_once "ez_sql_mysqli.php";
} elseif ($cfg['db_type'] == "mysql") {
  include_once "ez_sql_mysql.php";
} elseif ($cfg['db_type'] == "sqlite3") {
  include_once "ez_sql_sqlite3.php";
} else {
  trigger_error('Unsupported database type: "'.$cfg['db_type'].'"', E_USER_WARNING);
}

/**
 * Provides all functions needed by the individual scripts
 *
 * @author Christian Beer
 * @package ProFTPd-Admin
 *
 * @todo streamline usage of $config and create a Class for it
 * @todo make database calls generic to the caller
 * @todo create standard user and group objects
 */
class AdminClass {
    /**
     * database layer
     * @var ezSQLcore (ezSQL_mysql or ezSQL_sqlite3)
     */
    var $dbConn = false;
    /**
     * configuration store
     * @var Array
     */
    var $config = false;
    /**
     * version number
     * @access private
     * @var String
     */
    var $version = "2.2";

    /**
     * initialize the database connection via ezSQL_mysql
     * @param Array $cfg configuration array retrieved from config.php to store in the object
     */
    function AdminClass($cfg) {
        $this->config = $cfg;
        // if db_type is not set, default to mysqli
        if (!isset($cfg['db_type']) || $cfg['db_type'] == "mysqli") {
            $this->dbConn = new ezSQL_mysqli($this->config['db_user'], $this->config['db_pass'], $this->config['db_name'], $this->config['db_host']);
        } elseif ($cfg['db_type'] == "mysql") {
            $this->dbConn = new ezSQL_mysql($this->config['db_user'], $this->config['db_pass'], $this->config['db_name'], $this->config['db_host']);
        } elseif ($cfg['db_type'] == "sqlite3") {
            $this->dbConn = new ezSQL_sqlite3($this->config['db_path'], $this->config['db_name']);
        } else {
            trigger_error('Unsupported database type: "'.$cfg['db_type'].'"', E_USER_WARNING);
        }
    }

    /**
     * return the version number to outside class
     * @return String
     */
    function get_version() {
        return $this->version;
    }

    /**
     * retrieves groups for each user and populates an array of $data[ftpname][gid] = groupname
     * @return Array like $data[ftpname][gid] = groupname
     */
    function parse_groups($ftpname = false) {
        $format = 'SELECT * FROM %s';
        $query = sprintf($format, $this->config['table_groups']);
        $result = $this->dbConn->get_results($query);
        $data = array();
        if ($result) {
            $field_groupname = $this->config['field_groupname'];
            $field_gid = $this->config['field_gid'];
            $field_members = $this->config['field_members'];
            foreach ($result as $group) {
                $names = explode(",", $group->$field_members);
                reset($names);
                while (list($key, $name) = each($names)) {
                    $data[$name][$group->$field_gid] = $group->$field_groupname;
                }
            }
        }
        /* no ftpname provided, return all data */
        if ($ftpname === false) return $data;
        /* if there is data for provided ftpname, return only that */
        if (array_key_exists($ftpname, $data)) return $data[$ftpname];
        /* return nothing otherwise */
        return array();
    }

    /**
     * retrieves the list of groups and populates an array of $data[gid] = groupname
     * @return Array like $data[gid] = groupname
     */
    function get_groups() {
        $format = 'SELECT * FROM %s ORDER BY %s ASC';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_gid']);
        $result = $this->dbConn->get_results($query);
        $data = array();
        if ($result) {
            $field_gid = $this->config['field_gid'];
            $field_groupname = $this->config['field_groupname'];
            foreach ($result as $group) {
                $data[$group->$field_gid] = $group->$field_groupname;
            }
        }
        return $data;
    }

    /**
     * retrieves all users from db and populates an associative array
     * @return Array an array containing the users or false on failure
     */
    function get_users() {
        $format = 'SELECT * FROM %s ORDER BY %s ASC';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_id']);
        $result = $this->dbConn->get_results($query, ARRAY_A);
        if (!$result) return false;
        return $result;
    }

    /**
     * returns either the total number or the number of empty groups in the db
     * @param Boolean $only_emtpy
     * @return Integer number or false on error
     */
    function get_group_count($only_empty = false) {
        $format = 'SELECT COUNT(*) FROM %s';
        $query = sprintf($format, $this->config['table_groups']);
        if ($only_empty) {
            $where_format = ' WHERE %s=""';
            $where_clause = sprintf($where_format, $this->config['field_members']);
            $query .= $where_clause;
        }
        $result = $this->dbConn->get_var($query);
        return $result;
    }

    /**
     * returns either the total number or the number of disabled users in the db
     * @param Boolean $only_disabled
     * @return Integer number or false on error
     */
    function get_user_count($only_disabled = false) {
        $format = 'SELECT COUNT(*) FROM %s';
        $query = sprintf($format, $this->config['table_users']);
        if ($only_disabled) {
            $where_format = ' WHERE %s="1"';
            $where_clause = sprintf($where_format, $this->config['field_disabled']);
            $query .= $where_clause;
        }
        $result = $this->dbConn->get_var($query);
        return $result;
    }

    /**
     * returns the last index number of the user table
     * @return Integer
     */
    function get_last_uid() {
        $format = 'SELECT MAX(%s) FROM %s';
        $query = sprintf($format, $this->config['field_uid'], $this->config['table_users']);
        $result = $this->dbConn->get_var($query);
        return $result;
    }

    function get_last_ugid() {
        $format = 'SELECT MAX(%s) FROM %s';
        $query = sprintf($format, $this->config['field_ugid'], $this->config['table_groups']);
        $result = $this->dbConn->get_var($query);
        return $result;
    }

    /**
     * Checks if the given groupname is already in the database
     * @param String $groupname
     * @return boolean true if groupname exists, false if not
     */
    function check_groupname($groupname) {
        $format = 'SELECT 1 FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_groupname'], $groupname);
        $result = $this->dbConn->get_row($query);
        if (is_object($result)) return true;
        return false;
    }

    /**
     * Checks if the given gid is already in the database
     * @param String $gid
     * @return boolean true if gid exists, false if not
     */
    function check_gid($gid) {
        $format = 'SELECT 1 FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_gid'], $gid);
        $result = $this->dbConn->get_row($query);
        if (is_object($result)) return true;
        return false;
    }

    /**
     * Checks if the given username is already in the database
     * @param String $ftpname
     * @return boolean true if username exists, false if not
     */
    function check_username($ftpname) {
        $format = 'SELECT 1 FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_ftpname'], $ftpname);
        $result = $this->dbConn->get_row($query);
        if (is_object($result)) return true;
        return false;
    }

    /**
     * Checks if the given id is already in the database
     * @param String $id
     * @return boolean true if id exists, false if not
     */
    function check_id($id) {
        $format = 'SELECT 1 FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_id'], $id);
        $result = $this->dbConn->get_row($query);
        if (is_object($result)) return true;
        return false;
    }

    /**
     * Checks if the given uid is already in the database
     * @param String $uid
     * @return boolean true if id exists, false if not
     */
    function check_uid($uid) {
        $format = 'SELECT 1 FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_uid'], $uid);
        $result = $this->dbConn->get_row($query);
        if (is_object($result)) return true;
        return false;
    }

    /**
     * Adds a group entry into the database
     * @param Array $groupdata
     * @return Boolean true on success, false on failure
     */
    function add_group($groupdata) {
        $field_groupname = $this->config['field_groupname'];
        $field_gid       = $this->config['field_gid'];
        $field_members   = $this->config['field_members'];
        $format = 'INSERT INTO %s (%s,%s,%s) VALUES ("%s","%s","%s")';
        $query = sprintf($format, $this->config['table_groups'],
                                  $field_groupname,
                                  $field_gid,
                                  $field_members,
                                  $groupdata[$field_groupname],
                                  $groupdata[$field_gid],
                                  $groupdata[$field_members]);
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * Adds a user entry into the database
     * @param Array $userdata
     * @return Boolean true on success, false on failure
     */
    function add_user($userdata) {
        $field_ftpname   = $this->config['field_ftpname'];
        $field_uid      = $this->config['field_uid'];
        $field_login      = $this->config['field_login'];
        $field_passwd   = $this->config['field_passwd'];
        $field_path  = $this->config['field_path'];
        $field_shell    = $this->config['field_shell'];
        $field_create_date = $this->config['field_create_date'];
        $passwd_encryption = $this->config['passwd_encryption'];
        $passwd = "";
        if ($passwd_encryption == 'pbkdf2') {
          $passwd = hash_pbkdf2("sha1", $userdata[$field_passwd], $userdata[$field_ftpname], 5000, 40);
          $passwd = '"'.$passwd.'"';
        } else if ($passwd_encryption == 'crypt') {
          $passwd = unix_crypt($userdata[$field_passwd]);
          $passwd = '"'.$passwd.'"';
        } else if (strpos($passwd_encryption, "OpenSSL:") === 0) {
          $passwd_digest = substr($passwd_encryption, strpos($passwd_encryption, ':')+1);
          $passwd = 'CONCAT("{'.$passwd_digest.'}",TO_BASE64(UNHEX('.$passwd_digest.'("'.$userdata[$field_passwd].'"))))';
        } else {
          $passwd = $passwd_encryption.'("'.$userdata[$field_passwd].'")';
        }
        $format = 'INSERT INTO %s (%s,%s,%s,%s,%s,%s,%s) VALUES ("%s","%s","%s",%s,"%s","%s","%s")';
        $query = sprintf($format, $this->config['table_users'],
                                  $field_login,
                                  $field_ftpname,
                                  $field_uid,
                                  $field_passwd,
                                  $field_path,
                                  $field_shell,
                                  $field_create_date,
                                  $userdata[$field_login],
                                  $userdata[$field_ftpname],
                                  $userdata[$field_uid],
                                  $passwd,
                                  $userdata[$field_path],
                                  $userdata[$field_shell],
                                  date('Y-m-d H:i:s'));
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * retrieve a group by gid
     * @param Integer $gid
     * @return Object
     */
    function get_group_by_gid($gid) {
        if (empty($gid)) return false;
        $format = 'SELECT * FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_gid'], $gid);
        $result = $this->dbConn->get_row($query, ARRAY_A);
        if (!$result) return false;
        return $result;
    }

    /**
     * retrieve a user by ftpname
     * @param String $ftpname
     * @return Array
     */
    function get_user_by_ftpname($ftpname) {
        if (empty($ftpname)) return false;
        $format = 'SELECT * FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_ftpname'], $ftpname);
        $result = $this->dbConn->get_row($query, ARRAY_A);
        if (!$result) return false;
        return $result;
    }

    /**
     * retrieve a user by id
     * @param Integer $id
     * @return Array
     */
    function get_user_by_id($id) {
        if (empty($id)) return false;
        $format = 'SELECT * FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_id'], $id);
        $result = $this->dbConn->get_row($query, ARRAY_A);
        if (!$result) return false;
        return $result;
    }

    /**
     * retrieves user from database with given maingroup
     * and populates an array of $data[id] = ftpname
     * @param Integer $gid
     * @return Array form is $data[id] = ftpname
     */
    function get_users_by_gid($gid) {
        if (empty($gid)) return false;
        $format = 'SELECT %s, %s FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['field_id'], $this->config['field_ftpname'], $this->config['table_users'], $this->config['field_ugid'], $gid);
        $result = $this->dbConn->get_results($query);
        if (!$result) return false;

        $field_id = $this->config['field_id'];
        $field_ftpname = $this->config['field_ftpname'];
        $field_members = $this->config['field_members'];

        $data = array();
        foreach ($result as $user) {
            $data[$user->$field_id] = $user->$field_ftpname;
        }
        if (count($data) == 0) return false;
        return $data;
    }

    /**
     * retrieves user from database with given maingroup
     * and returns their count
     * @param Integer $gid
     * @return Integer number
     */
    function get_user_count_by_gid($gid) {
        if (empty($gid)) return false;
        $format = 'SELECT COUNT(*) FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_ugid'], $gid);
        $result = $this->dbConn->get_var($query);
        if (!$result) return 0;
        return $result;
    }

    /**
     * retrieves members from group and populates an array of $data[id] = ftpname
     * @param Integer $gid
     * @return Array form is $data[id] = ftpname
     */
    function get_add_users_by_gid($gid) {
        if (empty($gid)) return false;
        $group = $this->get_group_by_gid($gid);
        if (!$group) return false;

        $field_id = $this->config['field_id'];
        $field_ftpname = $this->config['field_ftpname'];
        $field_members = $this->config['field_members'];

        $ftpnames = explode(",", $group[$field_members]);
        $data = array();
        foreach ($ftpnames as $ftpname) {
            $user = $this->get_user_by_ftpname($ftpname);
            if (!$user) continue;
            $data[$user[$field_id]] = $user[$field_ftpname];
        }
        if (count($data) == 0) return false;
        return $data;
    }

    /**
     * retrieves user from database with given maingroup
     * and returns their count
     * @param Integer $gid
     * @return Integer number
     */
    function get_user_add_count_by_gid($gid) {
        if (empty($gid)) return false;
        $group = $this->get_group_by_gid($gid);
        if (!$group) return false;

        $field_id = $this->config['field_id'];
        $field_ftpname = $this->config['field_ftpname'];
        $field_members = $this->config['field_members'];

        $ftpnames = explode(",", $group[$field_members]);
        $data = array();
        foreach ($ftpnames as $ftpname) {
            $user = $this->get_user_by_ftpname($ftpname);
            if (!$user) continue;
            $data[$user[$field_id]] = $user[$field_ftpname];
        }
        return count($data);
    }

    /**
     * Adds a user to a group using the groupid
     * @param Integer $ftpname
     * @param Integer $gid
     * @return boolean false on error
     */
    function add_user_to_group($ftpname, $gid) {
        if (empty($ftpname) || empty($gid)) return false;
        $format = 'SELECT %s FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['field_members'], $this->config['table_groups'], $this->config['field_gid'], $gid);
        $result = $this->dbConn->get_var($query);
        if ($result != "") {
            if(strpos($result, $ftpname) !== false) {
                return true;
            } else {
                $members = $result.','.$ftpname;
            }
        } else {
            $members = $ftpname;
        }

        $format = 'UPDATE %s SET %s="%s" WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_members'], $members, $this->config['field_gid'], $gid);
        $result = $this->dbConn->query($query);
        if (!$result) return false;
        return true;
    }

    /**
     * removes a user from a given group using the groupid
     * @param Integer $ftpname
     * @param Integer $gid
     * @return boolean false on error
     */
    function remove_user_from_group($ftpname, $gid) {
        if (empty($ftpname) || empty($gid)) return false;
        $format = 'SELECT %s FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['field_members'], $this->config['table_groups'], $this->config['field_gid'], $gid);
        $result = $this->dbConn->get_var($query);
        if(strpos($result, $ftpname) === false) {
            return true;
        }
        $members_array = explode(",", $result);
        $members_new_array = array_diff($members_array, array("$ftpname", ""));
        if (is_array($members_new_array)) {
            $members_new = implode(",", $members_new_array);
        } else {
            $members_new = "";
        }

        $format = 'UPDATE %s SET %s="%s" WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_members'], $members_new, $this->config['field_gid'], $gid);
        $result = $this->dbConn->query($query);
        if (!$result) return false;
        return true;
    }

    /**
     * updates the group entry in the database (currently only the gid!)
     * @param Integer $gid
     * @param Integer $new_gid
     * @return Boolean true on success, false on failure
     */
    function update_group($gid, $new_gid) {
        $format = 'UPDATE %s SET %s="%s" WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_ugid'], $new_gid, $this->config['field_ugid'], $gid);
        $result = $this->dbConn->query($query);

        $query = sprintf($format, $this->config['table_groups'], $this->config['field_gid'], $new_gid, $this->config['field_gid'], $gid);
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * delete a group by gid
     * @param Integer $gid
     * @return Boolean true on success, false on failure
     */
    function delete_group_by_gid($gid) {
        $format = 'DELETE FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_groups'], $this->config['field_gid'], $gid);
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * updates the user entry in the database
     * @param Array $userdata
     * @return Boolean true on success, false on failure
     */
    function update_user($userdata) {
        $field_id       = $this->config['field_id'];
        $field_passwd   = $this->config['field_passwd'];
        $field_create_date = $this->config['field_create_date'];
        $passwd_encryption = $this->config['passwd_encryption'];

        $passwd_query = '';
        if (strlen($userdata[$field_passwd]) > 0) {
          $passwd_format = '';
          if ($passwd_encryption == 'pbkdf2') {
            $passwd = hash_pbkdf2("sha1", $userdata[$field_passwd], $userdata[$field_ftpname], 5000, 40);
            $passwd_format = ' %s="%s", ';
          } else if ($passwd_encryption == 'crypt') {
            $passwd = unix_crypt($userdata[$field_passwd]);
            $passwd_format = ' %s="%s", ';
          } else if (strpos($passwd_encryption, "OpenSSL:") === 0) {
            $passwd_digest = substr($passwd_encryption, strpos($passwd_encryption, ':')+1);
            $passwd = 'CONCAT("{'.$passwd_digest.'}",TO_BASE64(UNHEX('.$passwd_digest.'("'.$userdata[$field_passwd].'"))))';
            $passwd_format = ' %s=%s, ';
          } else {
            $passwd = $passwd_encryption.'("'.$userdata[$field_passwd].'")';
            $passwd_format = ' %s=%s, ';
          }
          $passwd_query = sprintf($passwd_format, $field_passwd, $passwd);
        }

        $format = 'UPDATE %s SET %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'],
                                  $passwd_query,
                                  $field_id,       $userdata[$field_id]);
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * removes the user entry from the database
     * @param Integer $id
     * @return Boolean true on success, false on failure
     */
    function remove_user_by_id($id) {
        $format = 'DELETE FROM %s WHERE %s="%s"';
        $query = sprintf($format, $this->config['table_users'], $this->config['field_id'], $id);
        $result = $this->dbConn->query($query);
        return $result;
    }

    /**
     * generate a random string
     * @param Integer $length default 6
     * @return String of random characters of the specified length
     */
    function generate_random_string($length = 6) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * check the validity of the id
     * @param Integer $id
     * @return Boolean true if the given id is a positive integer
     */
    function is_valid_id($id) {
        return is_numeric($id) && (int)$id > 0 && $id == round($id);
    }
}
?>
