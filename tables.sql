#
# Table structure for table `users`
#

CREATE TABLE `users` (
  `id` smallint(2) unsigned NOT NULL auto_increment,
  `login` varchar(32) NOT NULL default '',
  `userid` varchar(32) NOT NULL default '',
  `uid` smallint(6) unsigned default NULL,
  `gid` smallint(6) unsigned default NULL,
  `passwd` varchar(265) NOT NULL default '',
  `homedir` varchar(255) NOT NULL default '',
  `shell` varchar(32) NOT NULL default '/sbin/nologin',
  `disabled` smallint(2) unsigned NOT NULL default '0',
  `bytes_in_used` bigint(20) unsigned NOT NULL default '0',
  `bytes_out_used` bigint(20) unsigned NOT NULL default '0',
  `files_in_used` bigint(20) unsigned NOT NULL default '0',
  `files_out_used` bigint(20) unsigned NOT NULL default '0',
  `login_count` int(11) unsigned NOT NULL default '0',
  `last_login` datetime NOT NULL default '0000-00-00 00:00:00',
  `create_date` datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `userid` (`userid`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci COMMENT='ProFTPd FTP table';
