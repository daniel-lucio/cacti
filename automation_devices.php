<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include('./include/auth.php');
include('./lib/api_device.php');

$device_actions = array(
	1 => __('Add Device')
);

$os_arr = array_rekey(db_fetch_assoc('SELECT DISTINCT os 
	FROM automation_devices 
	WHERE os IS NOT NULL AND os!=""'), 'os', 'os');

$status_arr = array(
	__('Down'),
	__('Up')
);

$networks = array_rekey(db_fetch_assoc('SELECT an.id, an.name 
	FROM automation_networks AS an
	INNER JOIN automation_devices AS ad
	ON an.id=ad.network_id 
	ORDER BY name'), 'id', 'name');

set_default_action();

process_request_vars();

switch(get_request_var('action')) {
	case 'purge':
		purge_discovery_results();

		break;
	case 'actions':
		form_actions();

		break;
	case 'export':
		export_discovery_results();

		break;
	default:
		display_discovery_page();

		break;
}

function form_actions() {
	global $device_actions, $availability_options;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */
	
	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') { /* add to cacti */
				foreach($selected_items as $id) {
					$d = db_fetch_row_prepared("SELECT * FROM automation_devices WHERE id = ?", array($id));
					$d['host_template']       = get_filter_request_var('host_template');
					$d['availability_method'] = get_filter_request_var('availability_method');
					$d['notes']               = __('Added manually through device automation interface.');
					$d['snmp_sysName']        = $d['sysName'];

					// pull ping options from network_id
					$n = db_fetch_row_prepared("SELECT * FROM automation_networks WHERE id = ?", array($d['network_id']));
					if (sizeof($n)) {
						$d['ping_method']  = $n['ping_method'];
						$d['ping_port']    = $n['ping_port'];
						$d['ping_timeout'] = $n['ping_timeout'];
						$d['ping_retries'] = $n['ping_retries'];
					}

					$host_id = automation_add_device($d, true);

					if ($host_id) {
						$message .= "<span class='deviceUp'>" . __('Device') . ' ' . htmlspecialchars($d['description']) . ' ' . __('Added to Cacti') . '</span><br>';
					}else{
						$message .= "<span class='deviceDown'>" . __('Device') . ' ' . htmlspecialchars($d['description']) . ' ' . __('Not Added to Cacti') . '</span><br>';
					}
				}

				if (strlen($message)) {
					$_SESSION['automation_message'] = $message;
					raise_message('automation_message');
				}
			}
		}

		header('Location: automation_devices.php?header=false');
		exit;
	}

	/* setup some variables */
	$device_list = ''; $device_array = array(); $i = 0;

	/* loop through each of the graphs selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$device_list .= '<li>' . htmlspecialchars(db_fetch_cell_prepared('SELECT CONCAT(IF(hostname!="", hostname, "unknown"), " (", ip, ")") FROM automation_devices WHERE id = ?', array($matches[1]))) . '</li>';
			$device_array[$i] = $matches[1];

			$i++;
		}
	}

	top_header();

	form_start('automation_devices.php', 'chk');

	html_start_box($device_actions{get_request_var('drp_action')}, '60%', '', '3', 'center', '');

	$available_host_templates = db_fetch_assoc_prepared('SELECT id, name FROM host_template ORDER BY name');

	if (isset($device_array) && sizeof($device_array)) {
		if (get_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea odd'>
					<p>" . __('Click \'Continue\' to add the following Discovered device(s).') . "</p>
					<p><div class='itemlist'><ul>$device_list</ul></div></p>
				</td>
			</tr>
			<tr>
				<td class='textArea odd'>
					<table><tr><td>" . __('Select Template') . "</td><td>\n";

			form_dropdown('host_template', $available_host_templates, 'name', 'id', '', '', '');

			print "</td></tr>\n";

			print "<tr><td>" . __('Availability Method') . "</td><td>\n";

			form_dropdown('availability_method', $availability_options, '', '', '', '', '');

			print "</td></tr></table></td></tr>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Add Device(s)') . "'>";
		}
	}else{
		print "<tr><td class='odd'><span class='textError'>" . __('You must select at least one Device.') . "</span></td></tr>\n";
		$save_html = "<input type='button' value='" . __('Return') . "' onClick='cactiReturnTo()'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($device_array) ? serialize($device_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

function display_discovery_page() {
	global $item_rows, $os_arr, $status_arr, $networks, $device_actions;

	top_header();

	draw_filter();

	$total_rows = 0;
	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	$results = get_discovery_results($total_rows, $rows);

	/* generate page list */
	$nav = html_nav_bar('automation_devices.php', MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 12, __('Devices'), 'page', 'main');

	form_start('automation_devices.php', 'automation_devices');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'hostname'    => array('display' => __('Device Name'), 'align' => 'left', 'sort' => 'ASC'),
		'ip'          => array('display' => __('IP'),          'align' => 'left', 'sort' => 'ASC'),
		'sysName'     => array('display' => __('SNMP Name'),   'align' => 'left', 'sort' => 'ASC'),
		'sysLocation' => array('display' => __('Location'),    'align' => 'left', 'sort' => 'ASC'),
		'sysContact'  => array('display' => __('Contact'),     'align' => 'left', 'sort' => 'ASC'),
		'sysDescr'    => array('display' => __('Description'), 'align' => 'left', 'sort' => 'ASC'),
		'os'          => array('display' => __('OS'),          'align' => 'left', 'sort' => 'ASC'),
		'time'        => array('display' => __('Uptime'),      'align' => 'right', 'sort' => 'DESC'),
		'snmp'        => array('display' => __('SNMP'),        'align' => 'right', 'sort' => 'DESC'),
		'up'          => array('display' => __('Status'),      'align' => 'right', 'sort' => 'ASC'),
		'mytime'      => array('display' => __('Last Check'),  'align' => 'right', 'sort' => 'DESC'));

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$snmp_version        = read_config_option('snmp_ver');
	$snmp_port           = read_config_option('snmp_port');
	$snmp_timeout        = read_config_option('snmp_timeout');
	$snmp_username       = read_config_option('snmp_username');
	$snmp_password       = read_config_option('snmp_password');
	$max_oids            = read_config_option('max_get_size');
	$ping_method         = read_config_option('ping_method');
	$availability_method = read_config_option('availability_method');

	$status = array("<span class='deviceDown'>" . __('Down') . '</span>',"<span class='deviceUp'>" . __('Up') . '</span>');
	if (sizeof($results)) {
		foreach($results as $host) {
			form_alternate_row('line' . $host['network_id'], true);

			if ($host['sysUptime'] != 0) {
				$days = intval($host['sysUptime']/8640000);
				$hours = intval(($host['sysUptime'] - ($days * 8640000)) / 360000);
				$uptime = $days . 'd:' . $hours . 'h';
			} else {
				$uptime = '';
			}

			if ($host['hostname'] == '') {
				$host['hostname'] = __('Not Detected');
			}

			form_selectable_cell(filter_value($host['hostname'], get_request_var('filter')), $host['network_id']);
			form_selectable_cell(filter_value($host['ip'], get_request_var('filter')), $host['network_id']);
			form_selectable_cell(snmp_data($host['sysName']), $host['network_id'], '', 'text-align:left');
			form_selectable_cell(snmp_data($host['sysLocation']), $host['network_id'], '', 'text-align:left');
			form_selectable_cell(snmp_data($host['sysContact']), $host['network_id'], '', 'text-align:left');
			form_selectable_cell(snmp_data($host['sysDescr']), $host['network_id'], '', 'text-align:left');
			form_selectable_cell(snmp_data($host['os']), $host['network_id'], '', 'text-align:left');
			form_selectable_cell(snmp_data($uptime), $host['network_id'], '', 'text-align:right');
			form_selectable_cell($status[$host['snmp']], $host['network_id'], '', 'text-align:right');
			form_selectable_cell($status[$host['up']], $host['network_id'], '', 'text-align:right');
			form_selectable_cell(substr($host['mytime'],0,16), $host['network_id'], '', 'text-align:right');
			form_checkbox_cell($host['ip'], $host['network_id']);
			form_end_row();
		}
	}else{
		print "<tr class='even'><td colspan=11>" . __('No Devices Found') . "</td></tr>";
	}

	html_end_box(false);

	if (sizeof($results)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($device_actions);

	form_end();

	bottom_footer();
}

function process_request_vars() {
	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'pageset' => true,
			'default' => '-1'
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'default' => '1'

			),
		'filter' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'hostname', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'ASC', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'status' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'network' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'snmp' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'os' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
		)
	);

	validate_store_request_vars($filters, 'sess_autom');
	/* ================= input validation ================= */
}

function get_discovery_results(&$total_rows = 0, $rows = 0, $export = false) {
	global $os_arr, $status_arr, $networks, $device_actions;

	$sql_where  = '';
	$status     = get_request_var('status');
	$network    = get_request_var('network');
	$snmp       = get_request_var('snmp');
	$os         = get_request_var('os');
	$filter     = get_request_var('filter');

	if ($status == __('Down')) {
		$sql_where .= 'WHERE up=0';
	} else if ($status == __('Up')) {
		$sql_where .= 'WHERE up=1';
	}

	if ($network > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . 'network_id=' . $network;
	}

	if ($snmp == __('Down')) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . 'snmp=0';
	} else if ($snmp == __('Up')) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . 'snmp=1';
	}

	if ($os != '-1' && in_array($os, $os_arr)) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . "os='$os'";
	}

	if ($filter != '') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . "(hostname LIKE '%$filter%' OR ip LIKE '%$filter%')";
	}

	if ($export) {
		return db_fetch_assoc("SELECT * FROM automation_devices $sql_where ORDER BY INET_ATON(ip)");
	} else {
		$total_rows = db_fetch_cell("SELECT
			COUNT(*)
			FROM automation_devices
			$sql_where");

		$page = get_request_var('page');

		$sortby  = get_request_var('sort_column');
		if ($sortby=='ip') {
			$sortby = 'INET_ATON(ip)';
		}

		$sql_query = "SELECT *, FROM_UNIXTIME(time) AS mytime
			FROM automation_devices
			$sql_where
			ORDER BY " . $sortby . ' ' . get_request_var('sort_direction') . '
			LIMIT ' . ($rows*($page-1)) . ',' . $rows;

		return db_fetch_assoc($sql_query);
	}
}

function draw_filter() {
	global $item_rows, $os_arr, $status_arr, $networks, $device_actions;

	html_start_box(__('Discovery Filters'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td class='noprint'>
		<form id='form_devices' method='get' action='automation_devices.php'>
			<table class='filterTable'>
				<tr class='noprint'>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Network');?>
					</td>
					<td>
						<select id='network' onChange='applyFilter()'>
							<option value='-1' <?php if (get_request_var('network') == -1) {?> selected<?php }?>><?php print __('Any');?></option>
							<?php
							if (sizeof($networks)) {
							foreach ($networks as $key => $name) {
								print "<option value='" . $key . "'"; if (get_request_var('network') == $key) { print ' selected'; } print '>' . $name . "</option>\n";
							}
							}
							?>
						</select>
					<td>
						<input type='button' id='refresh' value='<?php print __('Go');?>' title='<?php print __('Set/Refresh Filters');?>'>
					</td>
					<td>
						<input type='button' id='clear' value='<?php print __('Clear');?>' title='<?php print __('Reset fields to defaults');?>'>
					</td>
					<td>
						<input type='button' id='export' value='<?php print __('Export');?>' title='<?php print __('Export to a file');?>'>
					</td>
					<td>
						<input type='button' id='purge' value='<?php print __('Purge');?>' title='<?php print __('Purge Discovered Devices');?>'>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Status');?>
					</td>
					<td>
						<select id='status' onChange='applyFilter()'>
							<option value='-1' <?php if (get_request_var('status') == '') {?> selected<?php }?>><?php print __('Any');?></option>
							<?php
							if (sizeof($status_arr)) {
							foreach ($status_arr as $st) {
								print "<option value='" . $st . "'"; if (get_request_var('status') == $st) { print ' selected'; } print '>' . $st . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('OS');?>
					</td>
					<td>
						<select id='os' onChange='applyFilter()'>
							<option value='-1' <?php if (get_request_var('os') == '') {?> selected<?php }?>><?php print __('Any');?></option>
							<?php
							if (sizeof($os_arr)) {
							foreach ($os_arr as $st) {
								print "<option value='" . $st . "'"; if (get_request_var('os') == $st) { print ' selected'; } print '>' . $st . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('SNMP');?>
					</td>
					<td>
						<select id='snmp' onChange='applyFilter()'>
							<option value='-1' <?php if (get_request_var('snmp') == '') {?> selected<?php }?>><?php print __('Any');?></option>
							<?php
							if (sizeof($status_arr)) {
							foreach ($status_arr as $st) {
								print "<option value='" . $st . "'"; if (get_request_var('snmp') == $st) { print ' selected'; } print '>' . $st . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Devices');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
							<?php
							if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td><input type='hidden' id='page' value='<?php print get_request_var('page');?>'></td>
				</tr>
			</table>
		</form>
		<script type='text/javascript'>

		$(function() {
			$('#refresh').click(function() {
				applyFilter();
			});

			$('#clear').click(function() {
				clearFilter();
			});

			$('#form_devices').submit(function(event) {
				event.preventDefault();
				applyFilter();
			});

			$('#purge').click(function() {
				loadPageNoHeader('automation_devices.php?header=false&action=purge&network_id='+$('#network').val());
			});

			$('#export').click(function() {
				document.location = 'automation_devices.php?export=1';
			});
		});
	
		function clearFilter() {
			loadPageNoHeader('automation_devices.php?header=false&clear=1');
		}

		function applyFilter() {
			strURL  = 'automation_devices.php?header=false';
			strURL += '&status=' + $('#status').val();
			strURL += '&network=' + $('#network').val();
			strURL += '&snmp=' + $('#snmp').val();
			strURL += '&os=' + $('#os').val();
			strURL += '&filter=' + $('#filter').val();
			strURL += '&rows=' + $('#rows').val();

			loadPageNoHeader(strURL);
		}

		</script>
		</td>
	</tr>
	<?php
	html_end_box();
}

function export_discovery_results() {
	$results = get_discovery_results($total_rows, 0, true);

	header('Content-type: application/csv');
	header('Content-Disposition: attachment; filename=discovery_results.csv');
	print "Host,IP,System Name,System Location,System Contact,System Description,OS,Uptime,SNMP,Status\n";

	if (sizeof($results)) {
	foreach ($results as $host) {
		if ($host['sysUptime'] != 0) {
			$days = intval($host['sysUptime']/8640000);
			$hours = intval(($host['sysUptime'] - ($days * 8640000)) / 360000);
			$uptime = $days . ' days ' . $hours . ' hours';
		} else {
			$uptime = '';
		}
		foreach($host as $h=>$r) {
			$host['$h'] = str_replace(',','',$r);
		}
		print ($host['hostname'] == '' ? __('Not Detected'):$host['hostname']) . ',';
		print $host['ip'] . ',';
		print export_data($host['sysName']) . ',';
		print export_data($host['sysLocation']) . ',';
		print export_data($host['sysContact']) . ',';
		print export_data($host['sysDescr']) . ',';
		print export_data($host['os']) . ',';
		print export_data($uptime) . ',';
		print ($host['snmp'] == 1 ? __('Up'):__('Down')) . ',';
		print ($host['up'] == 1 ? __('Up'):__('Down')) . "\n";
	}
	}
}

function purge_discovery_results() {
	get_filter_request_var('network');
	
	db_execute('TRUNCATE TABLE automation_devices' . (get_request_var('network') > 0 ? 'WHERE network_id=' . get_request_var('network'):''));

	header('Location: automation_devices.php?header=false');

	exit;
}

function snmp_data($item) {
	if ($item == '') {
		return 'N/A';
	}else{
		return htmlspecialchars(str_replace(':',' ', $item));
	}
}

function export_data($item) {
	if ($item == '') {
		return 'N/A';
	}else{
		return $item;
	}
}

?>
