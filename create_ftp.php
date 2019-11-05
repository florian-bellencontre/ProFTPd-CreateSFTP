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

include_once ("configs/config.php");
include_once ("includes/AdminClass.php");
global $cfg;

$ac = new AdminClass($cfg);

$field_userid   = $cfg['field_userid'];
$field_uid      = $cfg['field_uid'];
$field_ugid     = $cfg['field_ugid'];
$field_ad_gid   = 'ad_gid';
$field_passwd   = $cfg['field_passwd'];
$field_homedir  = $cfg['field_homedir'];
$field_shell    = $cfg['field_shell'];
$field_name     = $cfg['field_name'];
$field_company  = $cfg['field_company'];
$field_email    = $cfg['field_email'];
$field_comment  = $cfg['field_comment'];
$field_disabled = $cfg['field_disabled'];

$groups = $ac->get_groups();

if (count($groups) == 0) {
  $errormsg = 'There are no groups in the database; please create at least one group before creating users.';
}

/* Data validation */
if (empty($errormsg) && !empty($_REQUEST["action"]) && $_REQUEST["action"] == "create") {
  $errors = array();
  /* user id validation */
  if (empty($_REQUEST[$field_userid])
      || !preg_match($cfg['userid_regex'], $_REQUEST[$field_userid])
      || strlen($_REQUEST[$field_userid]) > $cfg['max_userid_length']) {
    array_push($errors, 'Invalid user name; user name must contain only letters, numbers, hyphens, and underscores with a maximum of '.$cfg['max_userid_length'].' characters.');
  }
  /* uid validation */
  if (empty($cfg['default_uid']) || !$ac->is_valid_id($cfg['default_uid'])) {
    array_push($errors, 'Invalid UID; must be a positive integer.');
  }
  if ($cfg['max_uid'] != -1 && $cfg['min_uid'] != -1) {
    if ($cfg['default_uid'] > $cfg['max_uid'] || $cfg['default_uid'] < $cfg['min_uid']) {
      array_push($errors, 'Invalid UID; UID must be between ' . $cfg['min_uid'] . ' and ' . $cfg['max_uid'] . '.');
    }
  } else if ($cfg['max_uid'] != -1 && $cfg['default_uid'] > $cfg['max_uid']) {
    array_push($errors, 'Invalid UID; UID must be at most ' . $cfg['max_uid'] . '.');
  } else if ($cfg['min_uid'] != -1 && $cfg['default_uid'] < $cfg['min_uid']) {
    array_push($errors, 'Invalid UID; UID must be at least ' . $cfg['min_uid'] . '.');
  }
  /* gid validation */
  if (empty($_REQUEST[$field_ugid]) || !$ac->is_valid_id($_REQUEST[$field_ugid])) {
    array_push($errors, 'Invalid main group; GID must be a positive integer.');
  }
  /* password length validation */
  if (strlen($_REQUEST[$field_passwd]) < $cfg['min_passwd_length']) {
    array_push($errors, 'Password is too short; minimum length is '.$cfg['min_passwd_length'].' characters.');
  }
//  if (strlen($_REQUEST[$field_homedir]) <= 1) {
//    array_push($errors, 'Invalid home directory; home directory cannot be empty.');
//  }
  /* shell validation */
  if (strlen($cfg['default_shell']) <= 1) {
    array_push($errors, 'Invalid shell; shell cannot be empty.');
  }
  /* user name uniqueness validation */
  if ($ac->check_username($_REQUEST[$field_userid])) {
    array_push($errors, 'User name already exists; name must be unique.');
  }
  /* gid existance validation */
  if (!$ac->check_gid($_REQUEST[$field_ugid])) {
    array_push($errors, 'Main group does not exist; GID cannot be found in the database.');
  }
  /* data validation passed */
  if (count($errors) == 0) {
    while (list($g_gid, $g_group) = each($groups)) { 
      if($_REQUEST[$field_ugid] == $g_gid) {
        $name_group = $g_group;
      }
    }
    $disabled = isset($_REQUEST[$field_disabled]) ? '1':'0';
    $userdata = array($field_userid   => $_REQUEST[$field_userid],
                      $field_uid      => $cfg['default_uid'],
                      $field_ugid     => $_REQUEST[$field_ugid],
                      $field_passwd   => $_REQUEST[$field_passwd],
                      $field_homedir  => $cfg['default_homedir'] . $name_group . "/" . $_REQUEST[$field_userid],
                      $field_shell    => $cfg['default_shell'],
                      $field_name     => $_REQUEST[$field_name],
                      $field_email    => $_REQUEST[$field_email],
                      $field_company  => $_REQUEST[$field_company],
                      $field_comment  => $_REQUEST[$field_comment],
                      $field_disabled => $disabled);
    if ($ac->add_user($userdata)) {
      if (isset($_REQUEST[$field_ad_gid])) {
        while (list($g_key, $g_gid) = each($_REQUEST[$field_ad_gid])) {
          if (!$ac->is_valid_id($g_gid)) {
            $warnmsg = 'Adding additional group failed; at least one of the additional groups had an invalid GID.';
            continue;
          }
          // XXX: fix error handling here
          $ac->add_user_to_group($_REQUEST[$field_userid], $g_gid);
        }
      }
      $infomsg = 'User "'.$_REQUEST[$field_userid].'" created successfully.';
    } else {
      $errormsg = 'User "'.$_REQUEST[$field_userid].'" creation failed; check log files.';
    }
  } else {
    $errormsg = implode($errors, "<br />\n");
  }
}

/* Form values */
if (isset($errormsg)) {
  /* This is a failed attempt */
  $userid   = $_REQUEST[$field_userid];
  $uid      = $cfg['default_uid'];
  $ugid     = $_REQUEST[$field_ugid];
  $ad_gid   = $_REQUEST[$field_ad_gid];
  $passwd   = $_REQUEST[$field_passwd];
  $homedir  = $cfg['default_homedir'];
  $shell    = $cfg['default_shell'];
  $name     = $_REQUEST[$field_name];
  $email    = $_REQUEST[$field_email];
  $company  = $_REQUEST[$field_company];
  $comment  = $_REQUEST[$field_comment];
  $disabled = isset($_REQUEST[$field_disabled]) ? '1' : '0';
} else {
  /* Default values */
  $userid   = "";
  if (empty($cfg['default_uid'])) {
    $uid    = $ac->get_last_uid() + 1;
  } else {
    $uid    = $cfg['default_uid'];
  }
  if (empty($infomsg)) {
    $ugid   = "";
    $ad_gid = array();
    $shell  = "/bin/false";
  } else {
    $ugid    = $_REQUEST[$field_ugid];
    $ad_gid = @$_REQUEST[$field_ad_gid];
    $shell  = $cfg['default_shell'];
  }
  $passwd   = $ac->generate_random_string((int) $cfg['default_passwd_length']);
  $homedir  = $cfg['default_homedir'];
  $name     = "";
  $email    = "";
  $company  = "";
  $comment  = "";
  $disabled = '0';
}

include ("includes/header.php");
?>
<?php include ("includes/messages.php"); ?>

<div class="col-xs-12 col-sm-8 col-md-6 center">
  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title">Add FTP</h3>
    </div>
    <div class="panel-body">
      <div class="row">
        <div class="col-sm-12">
          <form role="form" class="form-horizontal" method="post" data-toggle="validator">
	    <!-- Login -->
            <div class="form-group">
              <label for="<?php echo $field_comment; ?>" class="col-sm-4 control-label">You are</label>
              <div class="controls col-sm-8">
                <input type="text" class="form-control" id="<?php echo $field_username; ?>" name="<?php echo $field_username; ?>" value="<?php echo $_SERVER['PHP_AUTH_USER'] ?>" placeholder="Username" required disabled />
              </div>
            </div>
            <!-- FTP name -->
            <div class="form-group">
              <label for="<?php echo $field_userid; ?>" class="col-sm-4 control-label">FTP name <font color="red">*</font></label>
              <div class="controls col-sm-8">
                <input type="text" class="form-control" id="<?php echo $field_userid; ?>" name="<?php echo $field_userid; ?>" value="<?php echo $userid; ?>" placeholder="Mandatory user name" maxlength="<?php echo $cfg['max_userid_length']; ?>" pattern="<?php echo substr($cfg['userid_regex'], 2, -3); ?>" required />
                <p class="help-block"><small>Only letters, numbers, hyphens, and underscores. Maximum <?php echo $cfg['max_userid_length']; ?> characters.</small></p>
              </div>
            </div>
            <!-- Password -->
            <div class="form-group">
              <label for="<?php echo $field_passwd; ?>" class="col-sm-4 control-label">Password <font color="red">*</font></label>
              <div class="controls col-sm-8">
                <input type="text" class="form-control" id="<?php echo $field_passwd; ?>" name="<?php echo $field_passwd; ?>" value="<?php echo $passwd; ?>" placeholder="Mandatory password" minlength="<?php echo $cfg['min_passwd_length']; ?>" required disabled />
              </div>
            </div>
            <!-- Path -->
            <div class="form-group">
              <label for="<?php echo $field_name; ?>" class="col-sm-4 control-label">Path</label>
              <div class="controls col-sm-8">
                <input type="text" class="form-control" id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>" value="<?php echo $name; ?>" placeholder="" required disabled />
              </div>
            </div>
            <!-- Ticket number -->
            <div class="form-group">
              <label for="<?php echo $field_name; ?>" class="col-sm-4 control-label">Ticket number</label>
              <div class="controls col-sm-8">
                <input type="text" class="form-control" id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>" value="<?php echo $name; ?>" placeholder="XX-XXX" required />
              </div>
            </div>
            <!-- Actions -->
            <div class="form-group">
              <div class="col-sm-12">
                <a class="btn btn-default" href="ftp_list.php">&laquo; View users</a>
                <button type="submit" class="btn btn-primary pull-right" name="action" value="create">Create FTP</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include ("includes/footer.php"); ?>