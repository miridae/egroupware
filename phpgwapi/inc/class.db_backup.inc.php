<?php
	/**************************************************************************\
	* eGroupWare - Setup - db-backups                                          *
	* http://www.egroupware.org                                                *
	* --------------------------------------------                             *
	* Written and copyright by Ralf Becker <RalfBecker@outdoor-training.de>    *
	* --------------------------------------------                             *
	*  This program is free software; you can redistribute it and/or modify it *
	*  under the terms of the GNU General Public License as published by the   *
	*  Free Software Foundation; either version 2 of the License, or (at your  *
	*  option) any later version.                                              *
	\**************************************************************************/
	
	/* $Id$ */

	/**
	 * DB independent backup and restore of eGW's DB
	 *
	 * @class db_backup
	 * @author RalfBecker-AT-outdoor-training.de
	 * @license GPL
	 */

	class db_backup
	{
		var $schema_proc;	/** schema_proc class */
		var $schemas = array();		/** array tablename => schema */
		var $exclude_tables = array(	/** exclude from backup */
			'phpgw_sessions','phpgw_app_sessions',	// eGW's session-tables
			'phpgw_anglemail',	// email's cache
			'phpgw_felamimail_cache','phpgw_felamimail_folderstatus',	// felamimail's cache
		);
		var $system_tables = false;	/** regular expression to identify system-tables => ignored for schema+backup */
		var $egw_tables = false;	/** regurar expression to identify eGW tables => if set only they are used */
		var $backslash_token = '##!!**bAcKsLaSh**!!##';	// used in cvs_split

		/**
		 * Constructor
		 */
		function db_backup()
		{
			if (is_object($GLOBALS['phpgw_setup']->oProc))	// called from within setup
			{
				$this->schema_proc = $GLOBALS['phpgw_setup']->oProc;
			}
			else	// called from eGW
			{
				$this->schema_proc = CreateObject('phpgwapi.schema_proc');
			}
			$this->db = $this->schema_proc->m_odb;
			$this->adodb = &$GLOBALS['phpgw']->ADOdb;
			
			switch($this->db->Type)
			{
				case 'sapdb': 
				case 'maxdb':
					//$this->system_tables = '/^(sql_cursor.*|session_roles|activeconfiguration|cachestatistics|commandcachestatistics|commandstatistics|datastatistics|datavolumes|hotstandbycomponent|hotstandbygroup|instance|logvolumes|machineconfiguration|machineutilization|memoryallocatorstatistics|memoryholders|omslocks|optimizerinformation|sessions|snapshots|spinlockstatistics|version)$/i';
					$this->egw_tables = '/^(egw_|phpgw_)/i';
					break;
			}
		}
		
		/**
		 * Backup all data in the form of a (compressed) csv file
		 *
		 * @param f resource file opened with fopen for reading
		 */ 
		function restore($f)
		{
			@set_time_limit(0);
			ini_set('auto_detect_line_endings',true);
			
			$this->db->transaction_begin();

			// drop all existing tables
			foreach($this->adodb->MetaTables('TABLES') as $table)
			{
				if ($this->system_tables && preg_match($this->system_tables,$table) ||
					$this->egw_tables && !preg_match($this->egw_tables,$table))
				{
					 continue;
				}
				$this->schema_proc->DropTable($table);
			}	

			$table = False;
			$n = 0;
			while(!feof($f))
			{
				$line = trim(fgets($f)); ++$n;
				
				if (empty($line)) continue;
				
				if (substr($line,0,9) == 'charset: ')
				{
					$charset = substr($line,9);
					// needed if mbstring.func_overload > 0, else eg. substr does not work with non ascii chars
					@ini_set('mbstring.internal_encoding',$charset);
					continue;
				}
				if (substr($line,0,8) == 'schema: ')
				{
					// create the tables in the backup set
					$this->schemas = unserialize(trim(substr($line,8)));
					foreach($this->schemas as $table_name => $schema)
					{
						//echo "<pre>$table_name => ".$this->write_array($schema,1)."</pre>\n";
						$this->schema_proc->CreateTable($table_name,$schema);
					}
					// make the schemas availible for the db-class
					$GLOBALS['phpgw_info']['apps']['all-apps']['table_defs'] = &$this->schemas;
					continue;
				}
				if (substr($line,0,7) == 'table: ')
				{
					$table = substr($line,7);
					
					$cols = $this->csv_split($line=fgets($f)); ++$n;

					if (feof($f)) break;
					continue;
				}
				if ($table)	// do we already reached the data part
				{
					$data = $this->csv_split($line,$cols);

					if (count($data) == count($cols))
					{
						$this->db->insert($table,$data,False,__LINE__,__FILE__,'all-apps',true);
					}
					else 
					{
						echo '<p>'.lang("Line %1: '%2'<br><b>csv data does not match column-count of table %3 ==> ignored</b>",$n,$line,$table)."</p>\n";
						echo 'data=<pre>'.print_r($data,true)."</pre>\n";
					}
				}
			}
			// updated the sequences, if the DB uses them
			foreach($this->schemas as $table => $schema)
			{
				foreach($schema['fd'] as $column => $definition)
				{
					if ($definition['type'] == 'auto')
					{
						$this->schema_proc->UpdateSequence($table,$column);
						break;	// max. one per table
					}
				}
			}
			$this->db->transaction_commit();
		}
				
		/**
		 * Split one line of a csv file into an array and does all unescaping
		 * @internal
		 */
		function csv_split($line,$keys=False)
		{
			$fields = explode(',',trim($line));
			
			$str_pending = False;
			$n = 0;
			foreach($fields as $i => $field)
			{
				if ($str_pending !== False)
				{
					$field = $str_pending.','.$field;
					$str_pending = False;
				}
				$key = $keys ? $keys[$n] : $n;

				if ($field[0] == '"')
				{
					if (substr($field,-1) !== '"' || $field === '"')
					{
						$str_pending = $field;
						continue;
					}
					// count the number of backslashes before the finishing double-quote
					// it's not escaped if the number is even (incl. zero)
					for($anz_bslash = 0, $i = strlen($field)-2; $i >= 0 && $field[$i] == '\\'; --$i,++$anz_bslash);

					if ($anz_bslash & 1)	// ending double quote is escaped and does not end the string
					{
						$str_pending = $field;
						continue;
					}
					$arr[$key] = str_replace($this->backslash_token,'\\',str_replace(array('\\\\','\\n','\\r','\\"'),array($this->backslash_token,"\n","\r",'"'),substr($field,1,-1)));
				}
				else
				{
					$arr[$key] = $field == 'NULL' ? NULL : $field;
				}
				++$n;
			}
			return $arr;
		}

		/**
		 * @internal
		 */
		function escape_data(&$data,$col,$defs)
		{
			if (is_null($data))
			{
				$data = 'NULL';
			}
			else
			{
				switch($defs[$col]['type'])
				{
					case 'int': 
					case 'auto':
					case 'decimal':
					case 'date':
					case 'timestamp':
						break;
					default:
						$data = '"'.str_replace(array('\\',"\n","\r",'"'),array('\\\\','\\n','\\r','\\"'),$data).'"';
						break;
				}
			}
		}
		
		/**
		 * Backup all data in the form of a (compressed) csv file
		 *
		 * @param f resource file opened with fopen for writing
		 */ 
		function backup($f)
		{
			@set_time_limit(0);

			fwrite($f,"eGroupWare backup from ".date('Y-m-d H:i:s')."\n");

			fwrite($f,"\ncharset: ".$GLOBALS['phpgw']->translation->charset()."\n\n");

			$this->schema_backup($f);	// add the schema in a human readable form too
			
			/* not needed, already done by schema_backup
			foreach($this->adodb->MetaTables('TABLES') as $table)
			{
				if ($this->db->Type == 'sapdb' || $this->db->Type == 'maxdb')
				{
					$table = strtolower($table);
				}
				if (!($this->schemas[$table] = $this->schema_proc->GetTableDefinition($table)))
				{
					unset($this->schemas[$table]);
				} 
			}
			*/
			fwrite($f,"\nschema: ".serialize($this->schemas)."\n");

			foreach($this->schemas as $table => $schema)
			{
				if (in_array($table,$this->exclude_tables)) continue;	// dont backup

				$first_row = true;
				$this->db->select($table,'*',false,__LINE__,__FILE__);
				while(($row = $this->db->row(true)))
				{
					if ($first_row)
					{
						fwrite($f,"\ntable: $table\n".implode(',',array_keys($row))."\n");
						$first_row = false;
					}
					array_walk($row,array('db_backup','escape_data'),$schema['fd']);
					fwrite($f,implode(',',$row)."\n");
				}
			}
			return true;		
		}

		/**
		 * Backup all schemas in the form of a setup/tables_current.inc.php file
		 *
		 * @param f resource/boolean 
		 */ 
		function schema_backup($f=False)
		{
			foreach($this->adodb->MetaTables('TABLES') as $table)
			{
				if ($this->system_tables && preg_match($this->system_tables,$table) ||
					$this->egw_tables && !preg_match($this->egw_tables,$table))
				{
					continue;
				}
				if ($this->db->Type == 'sapdb' || $this->db->Type == 'maxdb')
				{
					$table = strtolower($table);
				}
				if (!($this->schemas[$table] = $this->schema_proc->GetTableDefinition($table)))
				{
					unset($this->schemas[$table]);
				}
				if (($this->db->Type == 'sapdb' || $this->db->Type == 'maxdb') && $table == 'phpgw_anglemail')
				{
					// sapdb does not differ between text and blob
					$this->schemas[$table]['fd']['content']['type'] = 'blob';
				}
			}
			$def = "\t\$phpgw_baseline = ";
			$def .= $this->write_array($this->schemas,1);
			$def .= ";\n";

			if ($f)
			{
				fwrite($f,$def);
			}
			else
			{
				if (!is_object($this->browser))
				{
					$this->browser = CreateObject('phpgwapi.browser');
				}
				$this->browser->content_header('schema-backup-'.date('YmdHi').'.inc.php','text/plain',strlen($def));
				echo "<?php\n\t/* eGroupWare schema-backup from ".date('Y-m-d H:i:s')." */\n\n".$def;
			}
		}
		
		/**
		 * @internal copied from etemplate/inc/class.db_tools.inc.php
		 */
		function write_array($arr,$depth,$parent='')
		{
			if (in_array($parent,array('pk','fk','ix','uc')))
			{
				$depth = 0;
			}
			if ($depth)
			{
				$tabs = "\n";
				for ($n = 0; $n < $depth; ++$n)
				{
					$tabs .= "\t";
				}
				++$depth;
			}
			$def = "array($tabs".($tabs ? "\t" : '');

			$n = 0;
			foreach($arr as $key => $val)
			{
				if (!is_int($key))
				{
					$def .= "'$key' => ";
				}
				if (is_array($val))
				{
					$def .= $this->write_array($val,$parent == 'fd' ? 0 : $depth,$key);
				}
				else
				{
					if (!$only_vals && $key === 'nullable')
					{
						$def .= $val ? 'True' : 'False';
					}
					else
					{
						$def .= "'$val'";
					}
				}
				if ($n < count($arr)-1)
				{
					$def .= ",$tabs".($tabs ? "\t" : '');
				}
				++$n;
			}
			$def .= "$tabs)";

			return $def;
		}
	}
/*
	$phpgw_info = array('flags' => array(
		'currentapp' => 'admin',
		'noheader' => true,
		'nonavbar' => true,
	));
	include '../../header.inc.php';
	
	$db_backup = new db_backup();
	
	//ini_set('mbstring.internal_encoding','iso-8859-1');
	//$str = '"c00cebe55f292105174b89133e5fc62a","ralf@pole","127.0.0.1",1091089152,1091089230,501';
	//$str = '306266,"hansjorg","7722959c3ad772bcc6ac608bced3d9f4","Hansj�rg","Helms",NULL,NULL,NULL,"A",-1,"u",NULL,0'."\n";
	//$str = '306266,"hansjorg","7722959c3ad772bcc6ac608bced3d9f4","Hansj�rg \\"Hansi\\"","Helms, Hansj�rg\\\\",NULL,NULL,NULL,"A",-1,"u",NULL,0'."\n";
	//$str = '"en","calendar","are you sure\\\\nyou want to\\\\ndelete this entry ?\\\\n\\\\nthis will delete\\\\nthis entry for all users.","Are you sure\\\\nyou want to\\\\ndelete this entry ?\\\\n\\\\nThis will delete\\\\nthis entry for all users."';
	//echo "'$str'=<pre>".print_r($db_backup->csv_split($str),true)."</pre>\n";
	//exit;

	if ($_GET['backup'])
	{
		$name = is_numeric($_GET['backup']) ? '/tmp/db_backup' : $_GET['backup'];

		if (!($f = fopen($file = "compress.bzip2://$name.bz2",'wb')) &&
			!($f = fopen($file = "compress.zlib://$name.gz",'wb')) &&
			!($f = fopen($file = "zlib:$name.gz",'wb')) &&	// php < 4.3
			!($f = fopen($file = $name,'wb')))
		{
			die("Cant open $name for writing");
		}
		$db_backup->backup($f);
		fclose($f);
	
		echo "<pre>";
		fpassthru(fopen($file,'r'));
	}
	elseif ($_GET['restore'])
	{
		$name = is_numeric($_GET['restore']) ? '/tmp/db_backup' : $_GET['restore'];

		if (!($f = fopen($file = "compress.bzip2://$name.bz2",'r')))
		{
			die("Cant open $name for reading");
		}
		$db_backup->restore($f);
		fclose($f);
		echo "DB restored from dump";
	}
	else
	{
		$db_backup->schema_backup();
	}
*/