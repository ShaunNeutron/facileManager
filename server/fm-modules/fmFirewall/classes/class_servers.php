<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2013 The facileManager Team                               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | facileManager: Easy System Administration                               |
 | fmFirewall: Easily manage one or more software firewalls                |
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/modules/fmfirewall/                        |
 +-------------------------------------------------------------------------+
*/

class fm_module_servers {
	
	/**
	 * Displays the server list
	 */
	function rows($result) {
		global $fmdb;
		
		echo '			<table class="display_results" id="table_edits" name="servers">' . "\n";
		if (!$result) {
			echo '<p id="noresult">There are no firewall servers.</p>';
		} else {
			?>
				<thead>
					<tr>
						<th width="20" style="text-align: center;"></th>
						<th>Hostname</th>
						<th>Serial No</th>
						<th>Firewall Type</th>
						<th>Method</th>
						<th>Config File</th>
						<th width="110" style="text-align: center;">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$num_rows = $fmdb->num_rows;
					$results = $fmdb->last_result;
					for ($x=0; $x<$num_rows; $x++) {
						$this->displayRow($results[$x]);
					}
					?>
				</tbody>
			<?php
		}
		echo '</table>';
	}

	/**
	 * Adds the new server
	 */
	function add($post) {
		global $fmdb, $__FM_CONFIG;
		
		/** Validate entries */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$module = ($post['module_name']) ? $post['module_name'] : $_SESSION['module'];

		/** Get a valid and unique serial number */
		$post['server_serial_no'] = (isset($post['server_serial_no'])) ? $post['server_serial_no'] : generateSerialNo($module);

		$sql_insert = "INSERT INTO `fm_{$__FM_CONFIG[$_SESSION['module']]['prefix']}servers`";
		$sql_fields = '(';
		$sql_values = null;
		
		$post['account_id'] = $_SESSION['user']['account_id'];
		
		$exclude = array('submit', 'action', 'server_id', 'compress', 'AUTHKEY', 'module_name', 'module_type', 'config');

		foreach ($post as $key => $data) {
			$clean_data = sanitize($data);
			if (($key == 'server_name') && empty($clean_data)) return 'No server name defined.';
			if (!in_array($key, $exclude)) {
				$sql_fields .= $key . ',';
				$sql_values .= "'$clean_data',";
			}
		}
		$sql_fields = rtrim($sql_fields, ',') . ')';
		$sql_values = rtrim($sql_values, ',');
		
		$query = "$sql_insert $sql_fields VALUES ($sql_values)";
		$result = $fmdb->query($query);
		
		if (!$result) return 'Could not add the server because a database error occurred.';

		addLogEntry("Added server:\nName: {$post['server_name']} ({$post['server_serial_no']})\nType: {$post['server_type']}\n" .
				"Update Method: {$post['server_update_method']}\nConfig File: {$post['server_config_file']}");
		return true;
	}

	/**
	 * Updates the selected server
	 */
	function update($post) {
		global $fmdb, $__FM_CONFIG;
		
		/** Validate entries */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$exclude = array('submit', 'action', 'server_id', 'compress', 'AUTHKEY', 'module_name', 'module_type', 'config', 'SERIALNO');

		$sql_edit = null;
		
		foreach ($post as $key => $data) {
			if (!in_array($key, $exclude)) {
				$sql_edit .= $key . "='" . sanitize($data) . "',";
			}
		}
		$sql = rtrim($sql_edit, ',');
		
		// Update the server
		$old_name = getNameFromID($post['server_id'], 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
		$query = "UPDATE `fm_{$__FM_CONFIG[$_SESSION['module']]['prefix']}servers` SET $sql WHERE `server_id`={$post['server_id']} AND `account_id`='{$_SESSION['user']['account_id']}'";
		$result = $fmdb->query($query);
		
		if (!$result) return 'Could not update the server because a database error occurred.';

//		setBuildUpdateConfigFlag(getServerSerial($post['server_id'], $_SESSION['module']), 'yes', 'build');
		
		$tmp_key = $post['server_key'] ? getNameFromID($post['server_key'], 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'keys', 'key_', 'key_id', 'key_name') : 'None';
		$tmp_runas = $post['server_run_as_predefined'] == 'as defined:' ? $post['server_run_as'] : $post['server_run_as_predefined'];
		addLogEntry("Updated server '$old_name' to:\nName: {$post['server_name']}\nType: {$post['server_type']}\n" .
					"Update Method: {$post['server_update_method']}\nConfig File: {$post['server_config_file']}");
		return true;
	}
	
	/**
	 * Deletes the selected server
	 */
	function delete($server_id) {
		global $fmdb, $__FM_CONFIG;
		
		/** Does the server_id exist for this account? */
		$server_serial_no = getNameFromID($server_id, 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_', 'server_id', 'server_serial_no');
		basicGet('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', $server_serial_no, 'server_', 'server_serial_no');
		if ($fmdb->num_rows) {
			/** Delete associated records from fm_{$__FM_CONFIG[$_SESSION['module']]['prefix']}track_builds */
			if (basicDelete('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'track_builds', $server_serial_no, 'server_serial_no', false) === false) {
				return 'The server could not be removed from the fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'track_builds table because a database error occurred.';
			}
			
			/** Delete server */
			$tmp_name = getNameFromID($server_id, 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			if (updateStatus('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', $server_id, 'server_', 'deleted', 'server_id')) {
				addLogEntry("Deleted server '$tmp_name' ($server_serial_no).");
				return true;
			}
		}
		
		return 'This server could not be deleted.';
	}


	function displayRow($row) {
		global $__FM_CONFIG, $allowed_to_manage_servers, $allowed_to_build_configs;
		
		$os_image = setOSIcon($row->server_os);
		
		$edit_status = $edit_actions = null;
//		$edit_actions = $row->server_status == 'active' ? '<a href="preview.php" onclick="javascript:void window.open(\'preview.php?server_serial_no=' . $row->server_serial_no . '\',\'1356124444538\',\'width=700,height=500,toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1,left=0,top=0\');return false;">' . $__FM_CONFIG['icons']['preview'] . '</a>' : null;
		
		if ($allowed_to_build_configs && $row->server_installed == 'yes') {
			if ($row->server_build_config == 'yes' && $row->server_status == 'active' && $row->server_installed == 'yes') {
				$edit_actions .= $__FM_CONFIG['icons']['build'];
			}
		}
		if ($allowed_to_manage_servers) {
			$edit_status = '<a class="edit_form_link" href="#">' . $__FM_CONFIG['icons']['edit'] . '</a>';
			if ($row->server_installed == 'yes') {
				$edit_status .= '<a href="' . $GLOBALS['basename'] . '?action=edit&id=' . $row->server_id . '&status=';
				$edit_status .= ($row->server_status == 'active') ? 'disabled' : 'active';
				$edit_status .= '">';
				$edit_status .= ($row->server_status == 'active') ? $__FM_CONFIG['icons']['disable'] : $__FM_CONFIG['icons']['enable'];
				$edit_status .= '</a>';
			}
			$edit_status .= '<a href="#" class="delete">' . $__FM_CONFIG['icons']['delete'] . '</a>';
		}
		if ($row->server_installed != 'yes') {
			$edit_actions = 'Client Install Required<br />';
		}
		$edit_status = $edit_actions . $edit_status;
		
		$edit_name = $row->server_name;
		
		$port = ($row->server_update_method != 'cron') ? '(tcp/' . $row->server_update_port . ')' : null;
		
		echo <<<HTML
		<tr id="$row->server_id">
			<td>$os_image</td>
			<td>$edit_name</td>
			<td>$row->server_serial_no</td>
			<td>$row->server_type</td>
			<td>$row->server_update_method $port</td>
			<td>$row->server_config_file</td>
			<td id="edit_delete_img">$edit_status</td>
		</tr>

HTML;
	}

	/**
	 * Displays the form to add new server
	 */
	function printForm($data = '', $action = 'add') {
		global $__FM_CONFIG;
		
		$server_id = 0;
		$server_name = $runas = $server_type = $server_update_port = null;
		$server_update_method = $server_config_file = null;
		$ucaction = ucfirst($action);
		
		if (!empty($_POST) && !array_key_exists('is_ajax', $_POST)) {
			if (is_array($_POST))
				extract($_POST);
		} elseif (@is_object($data[0])) {
			extract(get_object_vars($data[0]));
		}

		/** Show/hide divs */
		if (isset($server_run_as_predefined) && $server_run_as_predefined == 'as defined:') {
			$runashow = 'block';
		} else {
			$runashow = 'none';
			$server_run_as = null;
		}
		$server_update_port_style = ($server_update_method == 'cron') ? 'style="display: none;"' : 'style="display: block;"';
		
		$server_type = buildSelect('server_type', 'server_type', enumMYSQLSelect('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_type'), $server_type, 1);
		$server_update_method = buildSelect('server_update_method', 'server_update_method', enumMYSQLSelect('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_update_method'), $server_update_method, 1);
		
		$return_form = <<<FORM
		<form name="manage" id="manage" method="post" action="config-servers">
			<input type="hidden" name="action" value="$action" />
			<input type="hidden" name="server_id" value="$server_id" />
			<table class="form-table">
				<tr>
					<th width="33%" scope="row"><label for="server_name">Server Name</label></th>
					<td width="67%"><input name="server_name" id="server_name" type="text" value="$server_name" size="40" placeholder="dns1.local" /></td>
				</tr>
				<tr>
					<th width="33%" scope="row"><label for="server_type">Firewall Type</label></th>
					<td width="67%">$server_type</td>
				</tr>
				<tr>
					<th width="33%" scope="row"><label for="server_update_method">Update Method</label></th>
					<td width="67%">$server_update_method<div id="server_update_port_option" $server_update_port_style><input type="number" name="server_update_port" value="$server_update_port" placeholder="80" onkeydown="return validateNumber(event)" /></div></td>
				</tr>
				<tr>
					<th width="33%" scope="row"><label for="server_config_file">Config File</label></th>
					<td width="67%"><input name="server_config_file" id="server_config_file" type="text" value="$server_config_file" size="40" /></td>
				</tr>
			</table>
			<input type="submit" name="submit" value="$ucaction Firewall" class="button" />
			<input value="Cancel" class="button cancel" id="cancel_button" />
		</form>
FORM;

		return $return_form;
	}
	
	function buildServerConfig($serial_no) {
		global $fmdb, $__FM_CONFIG;
		
		/** Check serial number */
		basicGet('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', sanitize($serial_no), 'server_', 'server_serial_no');
		if (!$fmdb->num_rows) return '<p class="error">This server is not found.</p>';

		$server_details = $fmdb->last_result;
		extract(get_object_vars($server_details[0]), EXTR_SKIP);
		
		if (getOption('enable_named_checks', $_SESSION['user']['account_id'], 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'options') == 'yes') {
			include(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_buildconf.php');
			
			$data['SERIALNO'] = $server_serial_no;
			$data['compress'] = 0;
			$data['dryrun'] = true;
		
			basicGet('fm_accounts', $_SESSION['user']['account_id'], 'account_', 'account_id');
			$account_result = $fmdb->last_result;
			$data['AUTHKEY'] = $account_result[0]->account_key;
		
			$raw_data = $fm_module_buildconf->buildServerConfig($data);
		
			$response = $fm_module_buildconf->namedSyntaxChecks($raw_data);
			if (strpos($response, 'error') !== false) return $response;
		} else $response = null;
		
		switch($server_update_method) {
			case 'cron':
				/* set the server_update_config flag */
				setBuildUpdateConfigFlag($serial_no, 'yes', 'update');
				$response .= '<p>This server will be updated on the next cron run.</p>'. "\n";
				break;
			case 'http':
			case 'https':
				/** Test the port first */
				$port = ($server_update_method == 'https') ? 443 : 80;
				if (!socketTest($server_name, $port, 30)) {
					return $response . '<p class="error">Failed: could not access ' . $server_name . ' using ' . $server_update_method . ' (tcp/' . $port . ').</p>'. "\n";
				}
				
				/** Remote URL to use */
				$url = $server_update_method . '://' . $server_name . '/' . $_SESSION['module'] . '/reload.php';
				
				/** Data to post to $url */
				$post_data = array('action'=>'buildconf', 'serial_no'=>$server_serial_no);
				
				$post_result = unserialize(getPostData($url, $post_data));
				
				if (!is_array($post_result)) {
					/** Something went wrong */
					if (empty($post_result)) {
						$post_result = 'It appears ' . $server_name . ' does not have php configured properly within httpd.';
					}
					return $response . '<p class="error">' . $post_result . '</p>'. "\n";
				} else {
					if (count($post_result) > 1) {
						$response .= '<textarea rows="4" cols="100">';
						
						/** Loop through and format the output */
						foreach ($post_result as $line) {
							$response .= "[$server_name] $line\n";
						}
						
						$response .= "</textarea>\n";
					} else {
						$response .= "<p>[$server_name] " . $post_result[0] . '</p>';
					}
				}
		}
		
		/* reset the server_build_config flag */
		if (!strpos($response, strtolower('failed'))) {
			setBuildUpdateConfigFlag($serial_no, 'no', 'build');
		}

		$tmp_name = getNameFromID($serial_no, 'fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_', 'server_serial_no', 'server_name');
		addLogEntry("Built the configuration for server '$tmp_name'.");

		return $response;
	}
	
	function validatePost($post) {
		global $fmdb, $__FM_CONFIG;
		
		if (empty($post['server_name'])) return 'No server name defined.';
		
		/** Check name field length */
		$field_length = getColumnLength('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_name');
		if ($field_length !== false && strlen($post['server_name']) > $field_length) return 'Server name is too long (maximum ' . $field_length . ' characters).';
		
		/** Does the record already exist for this account? */
		basicGet('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', $post['server_name'], 'server_', 'server_name');
		if ($fmdb->num_rows) return 'This server name already exists.';
		
		if (empty($post['server_config_file'])) $post['server_config_file'] = $__FM_CONFIG['fw']['config_file'][$post['server_type']];
		
		/** Set default ports */
		if ($post['server_update_method'] == 'cron') {
			$post['server_update_port'] = 0;
		}
		if (!empty($post['server_update_port']) && !verifyNumber($post['server_update_port'], 1, 65535, false)) return 'Server update port must be a valid TCP port.';
		if (empty($post['server_update_port'])) {
			if ($post['server_update_method'] == 'http') $post['server_update_port'] = 80;
			elseif ($post['server_update_method'] == 'https') $post['server_update_port'] = 443;
		}
		
		return $post;
	}
	
}

if (!isset($fm_module_servers))
	$fm_module_servers = new fm_module_servers();

?>