<?php
/*
 * Wildcard Helper Classes
 * ACP - MyBB Plug-in Installer
 *
 * a generic installer for MyBB Plug-ins that accepts a data file and performs
 * installation functions in a non-destructive way according to the provided information
 */

class WildcardPluginInstaller
{
	private $db;

	protected $tables = array();
	protected $table_names = array();

	protected $columns = array();

	protected $settings = array();
	protected $setting_names = array();

	protected $settinggroups = array();
	protected $settinggroup_names = array();

	protected $templates = array();
	protected $template_names = array();

	protected $templategroups = array();
	protected $templategroup_names = array();

	/*
	 * load the installation data and prepare for anything
	 *
	 * @param string path to the install data
	 * @return void
	 */
	public function __construct($path)
	{
		if(!trim($path) || !file_exists($path))
		{
			return;
		}

		global $lang, $db;
		require_once $path;
		foreach(array('tables', 'columns', 'settings', 'templates') as $key)
		{
			if(!is_array($$key) || empty($$key))
			{
				continue;
			}

			$this->$key = $$key;
			switch($key)
			{
				case 'settings':
					$this->settinggroup_names = array_keys($settings);
					foreach($settings as $group => $info)
					{
						foreach($info['settings'] as $name => $setting)
						{
							$this->setting_names[] = $name;
						}
					}
					break;
				case 'templates':
					$this->templategroup_names = array_keys($templates);
					foreach($templates as $group => $info)
					{
						foreach($info['templates'] as $name => $template)
						{
							$this->template_names[] = $name;
						}
					}
					break;
				case 'columns':
					$this->columns = $columns;
					break;
				default:
					$singular = substr($key, 0, strlen($key) - 1);
					$property = "{$singular}_names";
					$this->$property = array_keys($$key);
					break;
			}
		}

		// snag a copy of the db object
		$this->db = $db;
	}

	/*
	 * install all the elements stored in the installation data file
	 *
	 * @return void
	 */
	public function install()
	{
		$this->add_tables();
		$this->add_columns();
		$this->add_settings();
		$this->add_templates();
	}

	/*
	 * uninstall all elements as provided in the install data
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->remove_tables();
		$this->remove_columns();
		$this->remove_('settinggroups', 'name', $this->settinggroup_names);
		$this->remove_('settings', 'name', $this->setting_names);
		$this->remove_('templategroups', 'prefix', $this->templategroup_names);
		$this->remove_('templates', 'title', $this->template_names);
		rebuild_settings();
	}

	/*
	 * create a correctly collated table from an array of options
	 *
	 * @param string table name without prefix
	 * @param array an associative array of columns
	 * @return void
	 */
	private function add_table($table, $columns)
	{
		static $collation;
		if(!isset($collation) || strlen($collation) == 0)
		{
			// only build collation for the first table
			$collation = $this->db->build_create_table_collation();
		}

		// build the column list
		$sep = $column_list = '';
		foreach($columns as $title => $definition)
		{
			$column_list .= "{$sep}{$title} {$definition}";
			$sep = ',';
		}

		// create the table if it doesn't already exist
		if(!$this->table_exists($table))
		{
			$table =  TABLE_PREFIX . $table;
			$this->db->write_query("CREATE TABLE {$table} ({$column_list}) ENGINE={$this->db->table_type}{$collation};");
		}
	}

	/*
	 * create multiple tables from stored info
	 *
	 * @param array an associative array of database tables and their columns
	 * @return void
	 */
	public function add_tables()
	{
		if(!is_array($this->tables) || empty($this->tables))
		{
			return false;
		}

		foreach($this->tables as $table => $columns)
		{
			if($this->table_exists($table))
			{
				// if it already exists, just check that all the columns are present (and add them if not)
				$this->add_columns(array($table => $columns));
			}
			else
			{
				$this->add_table($table, $columns);
			}
		}
	}

	/*
	 * drop multiple database tables
	 *
	 * @return void
	 */
	public function remove_tables()
	{
		if(!is_array($this->table_names) || empty($this->table_names))
		{
			return;
		}

		$drop_list = implode(', ' . TABLE_PREFIX, $this->table_names);
		$this->db->drop_table($drop_list);
	}

	/*
	 * add columns in the list to a table (if they do not already exist)
	 *
	 * @param array tables and columns
	 * @return void
	 */
	public function add_columns($columns = '')
	{
		if(!is_array($columns) || empty($columns))
		{
			$columns = $this->columns;
		}

		foreach($columns as $table => $all_columns)
		{
			$sep = $added_columns = '';
			foreach($all_columns as $title => $definition)
			{
				if(!$this->field_exists($table, $title))
				{
					$added_columns .= "{$sep}{$title} {$definition}";
					$sep = ', ADD ';
				}
			}
			if(strlen($added_columns) > 0)
			{
				// trickery, again
				$this->db->add_column($table, $added_columns, '');
			}
		}
	}

	/*
	 * drop multiple listed columns
	 *
	 * @param array tables and columns
	 * @return void
	 */
	public function remove_columns()
	{
		if(!is_array($this->columns) || empty($this->columns))
		{
			return;
		}

		foreach($this->columns as $table => $columns)
		{
			$sep = $dropped_columns = '';
			foreach($columns as $title => $definition)
			{
				if($this->field_exists($table, $title))
				{
					$dropped_columns .= "{$sep}{$title}";
					$sep = ', DROP ';
				}
			}
			if(strlen($dropped_columns) > 0)
			{
				// tricky, tricky xD
				$result = $this->db->drop_column($table, $dropped_columns);
			}
		}
	}

	/*
	 * create multiple setting groups
	 *
	 * @param array setting groups
	 * @return array setting groups and gids
	 */
	private function add_settinggroups($groups)
	{
		if(!is_array($groups) || empty($groups))
		{
			return false;
		}

		$insert_array = $gids = array();
		foreach($groups as $name => $group)
		{
			$query = $this->db->simple_select('settinggroups', 'gid', "name='{$name}'");
			if($this->db->num_rows($query) > 0)
			{
				$group['gid'] = (int) $this->db->fetch_field($query, 'gid');
				$gids[$name] = $group['gid'];
				$this->db->update_query('settinggroups', $group, "name='{$name}'");
			}
			else
			{
				$gid = $this->db->insert_query('settinggroups', $group);
				$gids[$name] = $gid;
			}
		}
		return $gids;
	}

	/*
	 * create settings from an array
	 *
	 * @param array groups and settings
	 * @return void
	 */
	public function add_settings()
	{
		if(!is_array($this->settings) || empty($this->settings))
		{
			return;
		}

		foreach($this->settings as $group => $data)
		{
			$gids = $this->add_settinggroups(array($group => $data['group']));
			$gid = $gids[$group];

			$insert_array = array();
			foreach($data['settings'] as $name => $setting)
			{
				$setting['gid'] = $gid;
				// does the setting already exist?
				$query = $this->db->simple_select('settings', 'sid', "name='{$name}'");
				if($this->db->num_rows($query) > 0)
				{
					$setting['sid'] = (int) $this->db->fetch_field($query, 'sid');

					// if so update the info (but leave the value alone)
					unset($setting['value']);
					$this->db->update_query('settings', $setting, "name='{$name}'");
				}
				else
				{
					$insert_array[] = $setting;
				}
			}
			if(!empty($insert_array))
			{
				$this->db->insert_query_multiple('settings', $insert_array);
			}
		}
		rebuild_settings();
	}

	/*
	 * create or update the template groups stored in the object
	 *
	 * @return void
	 */
	public function add_template_groups()
	{
		if(!is_array($this->templates) || empty($this->templates))
		{
			return;
		}

		$insert_array = $update_array = array();

		foreach($this->templates as $prefix => $data)
		{
			$query = $this->db->simple_select('templategroups', 'gid', "prefix='{$prefix}'");
			if($this->db->num_rows($query) > 0)
			{
				$gid = (int) $this->db->fetch_field($query, 'gid');
				$this->db->update_query('templategroups', $data['group'], "gid='{$gid}'");
			}
			else
			{
				$insert_array[] = $data['group'];
			}
		}

		if(!empty($insert_array))
		{
			$this->db->insert_query_multiple('templategroups', $insert_array);
		}
	}

	/*
	 * create multiple templates from stored info
	 *
	 * @param array template data
	 * @return void
	 */
	public function add_templates()
	{
		if(!is_array($this->templates) || empty($this->templates))
		{
			return;
		}

		$this->add_template_groups();

		$insert_array = array();
		foreach($this->templates as $group => $data)
		{
			foreach($data['templates'] as $title => $template)
			{
				$title = $this->db->escape_string($title);
				$template = $this->db->escape_string($template);
				$template_array = array(
					"title" => $title,
					"template" => $template,
					"sid" => -2,
					"version" => 1,
					"dateline" => TIME_NOW,
				);

				$query = $this->db->simple_select('templates', 'tid', "title='{$title}' AND sid IN('-2', '-1')");
				if($this->db->num_rows($query) > 0)
				{
					$tid = (int) $this->db->fetch_field($query, 'tid');
					$this->db->update_query('templates', $template_array, "tid='{$tid}'");
				}
				else
				{
					$insert_array[] = $template_array;
				}
			}
		}

		if(!empty($insert_array))
		{
			$this->db->insert_query_multiple('templates', $insert_array);
		}
	}

	/*
	 * removed rows from a named table when values of the named column
	 * are matched with members of the list
	 *
	 * @param string table name without prefix
	 * @param string field name
	 * @param array string values
	 * @return void
	 */
	private function remove_($table, $field, $list)
	{
		if(!is_array($list))
		{
			$list = array($list);
		}

		if(empty($list))
		{
			return;
		}

		if($this->table_exists($table) && $this->field_exists($table, $field))
		{
			$delete_list = "'" . implode("','", $list) . "'";
			$this->db->delete_query($table, "{$field} IN ({$delete_list})");
		}
	}

	/*
	 * verify the existence of named table
	 *
	 * @param string table name without prefix
	 * @return bool true if it exists/false if not
	 */
	public function table_exists($table)
	{
		static $table_list;

		if(!isset($table_list))
		{
			$table_list = $this->build_table_list();
		}
		return isset($table_list[$this->db->table_prefix . $table]);
	}

	/*
	 * build an array of all the tables in the current database
	 *
	 * @return array an array with keys for the table names and 1 for the values
	 */
	private function build_table_list()
	{
		global $config;

		$query = $this->db->write_query("
			SHOW TABLES
			FROM `{$config['database']['database']}`
		");

		$table_list = array();
		while ($row = $this->db->fetch_array($query)) {
			$table_list[array_pop($row)] = 1;
		}
		return $table_list;
	}

	/*
	 * verify the existence of the named column of the named table
	 *
	 * @param string table name without prefix
	 * @param string field name
	 * @return bool true if it exists/false if not
	 */
	public function field_exists($table, $field)
	{
		static $field_list;

		if(!isset($field_list[$table]))
		{
			$field_list[$table] = $this->build_field_list($table);
		}
		return isset($field_list[$table][$field]);
	}

	/*
	 * build an array of all the columns of the named table
	 *
	 * @param string table name without prefix
	 * @returns array an array with keys for the field names and 1 for the values
	 */
	private function build_field_list($table)
	{
		$field_list = array();

		$field_info = $this->db->show_fields_from($table);
		foreach($field_info as $info)
		{
			$field_list[$info['Field']] = 1;
		}
		return $field_list;
	}
}

?>
