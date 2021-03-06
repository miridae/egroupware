<?php
/**
 * EGgroupware admin - admin command: edit preferences
 *
 * @link http://www.egroupware.org
 * @author Hadi Nategh <hn@egroupware.org>
 * @package admin
 * @copyright (c) 2018 by Hadi Nategh <hn@egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Api;

/**
 * admin command: edit preferences
 *
 * @property-read int $account numerical account_id
 * @property-read string $pref "user", "default", "forced" or "group"
 * @property-read string $app app-name
 * @property-read array $set values to set
 * @property-read array $old old values
 */
class admin_cmd_edit_preferences extends admin_cmd
{
	/**
	 * Constructor
	 * @param string|int|array $account account name or id, or array with all parameters
	 * @param string $type ="user" "user", "default", "forced" or "group"
	 * @param string $app =null app-name, required if $account is no array
	 * @param array $set =null name => value pairs to change
	 * @param array $old =null name => value pairs of old values
	 * @param array $extra =array() values for keys requested(_email) or comment
	 */
	function __construct($account, $type=null, $app=null, array $set=null, array $old=null, array $extra=array())
	{
		if (!is_array($account))
		{
			$account = array(
				'account' => $account,
				'pref' => $type,	// type is __CLASS__!
				'app' => $app,
				'set' => $set,
				'old' => $old,
			)+$extra;
		}

		admin_cmd::__construct($account);
	}

	/**
	 * Edit a preference
	 *
	 * @param boolean $check_only =false only run the checks (and throw the exceptions), but not the command itself
	 * @return string
	 */
	protected function exec($check_only=false)
	{
		switch($this->pref)
		{
			case 'forced':
			case 'default':
				unset($this->account);
				break;
			case 'user':
				$this->account = self::parse_account($this->account, true);
				break;
			case 'group':
				$this->account = self::parse_account($this->account, false);
				break;
			default:
				throw new Api\Exception\WrongUserinput(lang('Invalid type "%1"!', $this->pref));
		}
		if ($this->app !== 'common') self::parse_apps(array($this->app));

		if ($check_only) return;

		$prefs = new Api\Preferences(in_array($this->pref, 'default', 'forced') ? $this->pref : $this->account);
		$prefs->read_repository();
		foreach($this->set as $name => $value)
		{
			if (!isset($value) || $value === '')
			{
				$prefs->delete($this->app, $name, in_array($this->pref, 'default', 'forced') ? $this->pref : 'user');
			}
			else
			{
				$prefs->add($this->app, $name, $value, in_array($this->pref, 'default', 'forced') ? $this->pref : 'user');
			}
		}
		$prefs->save_repository(true, $this->pref);
		return lang('Preferences saved.');
	}

	/**
	 * Return a title / string representation for a given command, eg. to display it
	 *
	 * @return string
	 */
	function __tostring()
	{
		switch($this->pref)
		{
			case 'forced':
				return lang('Forced preferences');

			case 'default':
				return lang('Default preferences');
		}
		return lang('Preferences').' '.self::display_account($this->account);
	}
}