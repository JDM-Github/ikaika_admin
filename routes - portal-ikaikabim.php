<?php
namespace portalikaikabim\routes\portalapps;
use portalikaikabim\services\airtableServices\AirtableHelper;
use WP_REST_Request,WP_REST_Response;

//------------------------------------------------------------------------------------
//                            REST API REGISTRATION
//------------------------------------------------------------------------------------
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/currentUser', [
        'methods'  => 'GET',
        'callback' => 'getCurrentUser',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------TIME MONITORING-------------------------------------
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/useraccounts', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserAccounts',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/workoffset', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createWorkOffsetRecord',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/overtime', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createOvertimeRequest',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/earncodes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEarnCodes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/calendarearncodes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getCalendarEarnCodes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/activitycodes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getActivityCodes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/holidays', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getHolidayDates',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/tm/userwarnings', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserWarnings',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
//----------------------PROJECT MANAGEMENT----------------------------------
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/projects', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjects',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/reports', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserReports',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/offset', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserOffsetRecords',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/reports', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createUserReports',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/reports', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\patchUserReports',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/reports', [
        'methods'  => 'DELETE',
        'callback' => __NAMESPACE__ . '\\deleteUserReports',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/projectscopes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjectScopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/project-assigned-scopes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjectAssignedScopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/clients', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjectClients',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/projectAllowedActivities', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjectAllowedActivities',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/getFieldsOfSpecificType', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getFieldsWithSpecificType',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/createProject', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createProject',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/modifyProject', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\modifyProject',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/deleteProject', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\deleteProject',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/modifyProjectAssignedScope', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\modifyProjectAssignedScopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/createProjectAssignedScope', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createProjectAssignedScopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/deleteProjectAssignedScope', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\deleteProjectAssignedScopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/userReportScopeProgress', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserReportScopeProgress',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/userReportScopeProgress', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createUserReportScopeProgress',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/userReportScopeProgress', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\REDACTED_AIRTABLE_PAT',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/userReportScopeProgress', [
        'methods'  => 'DELETE',
        'callback' => __NAMESPACE__ . '\\deleteUserReportScopeProgress',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/projectActionHistory', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjectActionHistory',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/pm/updateProjectActionHistory', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\updateProjectActionHistory',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------BUDGET TRACKER--------------------------------------

add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/accounts', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createPurchasingAccount',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/accounts', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getPurchasingAccounts',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/transactions', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getTransactions',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/transactiontypes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getTransactionTypes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/transactions', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createTransactions',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/envelopes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEnvelopes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/envelopegroups', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEnvelopeGroupChoices',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/management/purchasing/envelopes', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createEnvelope',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//--------------------------------------------------------------------------

//-------------------REIMBURSEMENT------------------------------------------
add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/reimbursements', [
		'methods'  => 'POST',
		'callback' => __NAMESPACE__ . '\\createReimbursementRequest',
		'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
		}
	]);
});

add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/reimbursements/(?P<id>[a-zA-Z0-9]+)', [
		'methods'  => 'PATCH',
		'callback' => __NAMESPACE__ . '\\REDACTED_AIRTABLE_PAT',
		'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
		}
	]);
});

add_action('rest_api_init', function () {
	register_rest_route('api/v2', '/reimbursements', [
		'methods'  => 'GET',
		'callback' => __NAMESPACE__ . '\\getReimbursementRecords',
		'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via cookie/session
    		if (validateCookieSession()) {
        		return true;
    		}
    		return false;
		}
	]);
});

//------------------------------------------------------------------------

//-------------------------------------------------------------------------------------
//								GLOBALS
//-------------------------------------------------------------------------------------
//airtable
$transaction_tracker_baseId = 'appp5MogesHN2eVvG';
$transaction_tracker_accounts_tableId = 'tbluWKBN7zWuPMjON';
$transaction_tracker_envelopes_tableId = 'tblbeCM0N32nUcQe0';
$transaction_tracker_transactions_tableId = 'tblKpuFbcyc6YfmH1';
$transaction_tracker_envelope_tableId = 'tblbeCM0N32nUcQe0';

$users_and_projects_baseId = 'app8jGTzRDQt4yPIa';
$users_and_projects_requests_tableId = 'tblNqKviuP8XaRPar';
$users_and_projects_earncodes_tableId = 'tbl3ZjKB95yckcwcJ';
$users_and_projects_users_tableId = 'tblxk72TlMeFDOl8J';
$users_and_projects_projects_tableId = 'tblM1YUKzuiMqISmC';
$users_and_projects_clients_tableId = 'tblaUeddS3nOJrXHX';
$users_and_projects_activitycodes_tableId = 'tbl9ANPghOOi7toup';
$users_and_projects_userreports_tableId = 'tblXUjJ26Rj95RPRl';
$users_and_projects_warnings_tableId = 'tbl0I6oTDN0cuLpA3';
$users_and_projects_projectallowedactivities_tableId = 'tbl53is3fy1C7We0E';
$users_and_projects_t3activities_tableId = 'tbldhjjQAV0pqKzIz';
$users_and_projects_project_scopes_tableId = 'tblbakHaKGLu4FecT';
$users_and_projects_t4tasks_tableId = 'tblipfyqQgXQ0dVjG';
$users_and_projects_t1processes_tableId = 'tbly6jy78GWm9tilj';
$users_and_projects_t2procedures_tableId = 'tblAHKe9S2qNdQFHe';
$users_and_projects_userreports_scopeprogress = 'tbldfzazKRu33qcPu';
$users_and_projects_projectactionhistory_tableId = 'tblH9hHFlvfZwVqJb';

$calendar_baseId = 'appxjrrZc99jJlpli';
$calendar_holidays_tableId = 'tbl908QwZNBN8KZCi';
$calendar_earnCodes_tableId = 'tblQNuzeMP3p63Efa';

$reimbursements_tableId = 'tblLS2KumEan1DTUw';
//--------------------------------------------------------------------------------------
//										ACTIONS
//--------------------------------------------------------------------------------------

//-----------------------------TIME MONITORING------------------------------------------
function getUserAccounts(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_users_tableId;
	$airtableHelper = new AirtableHelper();
	
	//fetch existing accounts
    $fetchUserAccounts = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_users_tableId
	]);
	
	//error
	if (!$fetchUserAccounts['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch user accounts!', 
			'error' => $fetchUserAccounts['error'] // show the real reason 
		], 500);
	}
	else {
		//check for duplicating accounts
		$records = $fetchUserAccounts['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getActivityCodes(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_activitycodes_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchActivityCodes = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_activitycodes_tableId
	]);
	
	//error
	if (!$fetchActivityCodes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch activity codes!', 
			'error' => $fetchActivityCodes['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchActivityCodes['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getHolidayDates(WP_REST_Request $request){
    global $calendar_baseId,$calendar_holidays_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchHolidays = $airtableHelper->fetchRecords ([
		'baseId' => $calendar_baseId,
		'tableId' => $calendar_holidays_tableId
	]);
	
	//error
	if (!$fetchHolidays['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch holidays!', 
			'error' => $fetchHolidays['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchHolidays['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getEarnCodes(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_earncodes_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchEarnCodes = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_earncodes_tableId
	]);
	
	//error
	if (!$fetchEarnCodes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch earncodes!', 
			'error' => $fetchEarnCodes['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchEarnCodes['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getCalendarEarnCodes(WP_REST_Request $request){
    global $calendar_baseId,$calendar_earnCodes_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchEarnCodes = $airtableHelper->fetchRecords ([
		'baseId' => $calendar_baseId,
		'tableId' => $calendar_earnCodes_tableId
	]);
	
	//error
	if (!$fetchEarnCodes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch earncodes!', 
			'error' => $fetchEarnCodes['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchEarnCodes['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getUserWarnings(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_warnings_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchUserWarnings = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_warnings_tableId
	]);
	
	//error
	if (!$fetchUserWarnings['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch earncodes!', 
			'error' => $fetchUserWarnings['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchUserWarnings['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function createWorkOffsetRecord(WP_REST_Request $request){
	global $users_and_projects_baseId,$users_and_projects_requests_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_requests_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to Upload Work Offset Record!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
	    return new WP_REST_Response([ 
			'success' => true, 
			'message' => 'New Work Offset Record Created!'
		], 200);
	}
}
function createOvertimeRequest(WP_REST_Request $request){
	global $users_and_projects_baseId,$users_and_projects_requests_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_requests_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to Upload Overtime Request!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
	    return new WP_REST_Response([ 
			'success' => true, 
			'message' => 'New Overtime Request Created!'
		], 200);
	}
}

//-----------------------------PROJECT MANAGEMENT--------------------------------------
function getProjects(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_projects_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchProjects = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projects_tableId
	]);
	
	//error
	if (!$fetchProjects['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch projects!', 
			'error' => $fetchProjects['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchProjects['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getUserReports(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_userreports_tableId;
    $filter = $request->get_param('filter');

    // Decode if exists
    if ($filter) {
        $filter = json_decode($filter, true);
    }

    $airtableHelper = new AirtableHelper();

    $fetchUserReports = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_userreports_tableId
    ], $filter);

    if (!$fetchUserReports['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch user reports!',
            'error' => $fetchUserReports['error']
        ], 500);
    } else {
        return new WP_REST_Response([
            'success' => true,
            'data' => $fetchUserReports['result'],
        ], 200);
    }
}
function getUserOffsetRecords(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_requests_tableId;
    $filter = $request->get_param('filter');

    // Decode if exists
    if ($filter) {
        $filter = json_decode($filter, true);
    }

    $airtableHelper = new AirtableHelper();

    $fetchUserOffsetRecords = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_requests_tableId
    ], $filter);

    if (!$fetchUserOffsetRecords['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch user offset records!',
            'error' => $fetchUserOffsetRecords['error']
        ], 500);
    } else {
        return new WP_REST_Response([
            'success' => true,
            'data' => $fetchUserOffsetRecords['result'],
        ], 200);
    }
}
function createUserReports(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_userreports_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_userreports_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload user report!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $uploadResult['result'],
			'message' => 'User Report successfully uploaded!'
		], 200);
	}
}
function patchUserReports(WP_REST_Request $request) {
    global $users_and_projects_baseId, $users_and_projects_userreports_tableId;
    $body = json_decode($request->get_body(), true);

    if (!$body) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $payload = $body['payload'] ?? null;

    if (empty($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing payload'
        ], 400);
    }

    $patchResult = patchAirtableRecords(
        $users_and_projects_baseId,
        $users_and_projects_userreports_tableId,
        AIRTABLE_API_KEY,
        $payload // can be single OR array
    );

    if (!$patchResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to update user reports',
            'error'   => $patchResult['error'] ?? null
        ], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => $patchResult['records'] ?? [],
        'message' => 'User reports updated successfully'
    ], 200);
}
function deleteUserReports(WP_REST_Request $request) {
    global $users_and_projects_baseId, $users_and_projects_userreports_tableId;
    $body = json_decode($request->get_body(), true);

    if (!$body) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $recordIds = $body['recordIds'] ?? null;

    if (empty($recordIds)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing record IDs'
        ], 400);
    }

    $deleteResult = deleteAirtableRecords(
        $users_and_projects_baseId,
        $users_and_projects_userreports_tableId,
        $recordIds,
        AIRTABLE_API_KEY
    );

    if (!$deleteResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to delete user reports',
            'error'   => $deleteResult['error'] ?? null
        ], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => $deleteResult['response'] ?? [],
        'message' => 'User reports deleted successfully'
    ], 200);
}
function getProjectScopes(WP_REST_Request $request){
    global $users_and_projects_baseId,
        $users_and_projects_t3activities_tableId, 
        $users_and_projects_t1processes_tableId,
        $users_and_projects_t2procedures_tableId,
        $users_and_projects_t4tasks_tableId;
    
    $filter = $request->get_param('filter');

    // Decode if exists
    if ($filter) {
        $filter = json_decode($filter, true);
    }

    $airtableHelper = new AirtableHelper();
    
    $fetchT1Processes = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_t1processes_tableId
    ], $filter);
    $fetchT2Procedures = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_t2procedures_tableId
    ], $filter);
    $fetchT3Activities = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_t3activities_tableId
    ], $filter);
    $fetchT4Tasks = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_t4tasks_tableId
    ], $filter);

    if (!$fetchT1Processes['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch T1 Processes records!',
            'error' => $fetchT1Processes['error']
        ], 500);
    }
    if (!$fetchT2Procedures['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch T2 Procedures records!',
            'error' => $fetchT2Procedures['error']
        ], 500);
    }
    if (!$fetchT3Activities['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch T3 Activities records!',
            'error' => $fetchT3Activities['error']
        ], 500);
    }
    if (!$fetchT4Tasks['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch T4 Tasks records!',
            'error' => $fetchT4Tasks['error']
        ], 500);
    }
    
    $projectScopes = [
        't1' => $fetchT1Processes['result'],
        't2' => $fetchT2Procedures['result'],
        't3' => $fetchT3Activities['result'],
        't4' => $fetchT4Tasks['result'],
    ];
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $projectScopes,
    ], 200);
}
function getProjectAssignedScopes(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_project_scopes_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchProjectAssignedScopes = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_project_scopes_tableId
	]);
	
	//error
	if (!$fetchProjectAssignedScopes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch project allowed activities!', 
			'error' => $fetchProjectAssignedScopes['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchProjectAssignedScopes['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getProjectClients(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_clients_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchProjectClients = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_clients_tableId
	]);
	
	//error
	if (!$fetchProjectClients['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch project clients!', 
			'error' => $fetchProjectClients['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchProjectClients['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getProjectAllowedActivities(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_projectallowedactivities_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchProjectAllowedActivities = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projectallowedactivities_tableId
	]);
	
	//error
	if (!$fetchProjectAllowedActivities['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch project allowed activities!', 
			'error' => $fetchProjectAllowedActivities['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchProjectAllowedActivities['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getFieldsWithSpecificType(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_projectallowedactivities_tableId;
    $tableName = $request->get_param('tableName');
    $fieldType = $request->get_param('fieldType');
	$airtableHelper = new AirtableHelper();
	
    $fetchMatchedFieldOfType = $airtableHelper->getFieldsWithSpecificFieldType ($tableName, $fieldType, [
		'baseId' => $users_and_projects_baseId
	]);
	
	//error
	if (!$fetchMatchedFieldOfType['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch field of specific type!', 
			'error' => $fetchMatchedFieldOfType['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchMatchedFieldOfType['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function createProject(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_projects_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projects_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload project!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $uploadResult['result'],
			'message' => 'Project successfully uploaded!'
		], 200);
	}
}
function modifyProject(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_projects_tableId;
    $payload = $request->get_param('payload'); // the data to patch
    $airtableHelper = new AirtableHelper();

    $patchResult = $airtableHelper->patchRecords($payload, [
        'baseId'  => $users_and_projects_baseId,
        'tableId' => $users_and_projects_projects_tableId
    ]);

    // error handling
    if(!$patchResult['success']){
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to patch user report!',
            'error' => $patchResult['error']
        ], 500);
    }
    else{
        return new WP_REST_Response([
            'success' => true,
            'data' => $patchResult['result'],
            'message' => 'User Report successfully patched!'
        ], 200);
    }
}
function deleteProject(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_projects_tableId;
    $payload = $request->get_param('payload'); // the data to delete
    $airtableHelper = new AirtableHelper();
	
	$deleteResult = $airtableHelper->deleteRecords ($payload,[
	    'baseId'  => $users_and_projects_baseId,
        'tableId' => $users_and_projects_projects_tableId
    ]);
		
	//error handling
	if(!$deleteResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to delete project!', 
			'error' => $deleteResult['error']
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $deleteResult['result'],
			'message' => 'Project successfully deleted!'
		], 200);
	}
}
function createProjectAssignedScopes(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_project_scopes_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_project_scopes_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload project assigned scope!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $uploadResult['result'],
			'message' => 'Project Assigned Scope successfully uploaded!'
		], 200);
	}
}
function modifyProjectAssignedScopes(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_project_scopes_tableId;
    $payload = $request->get_param('payload'); // the data to patch
    $airtableHelper = new AirtableHelper();

    $patchResult = $airtableHelper->patchRecords($payload, [
        'baseId'  => $users_and_projects_baseId,
        'tableId' => $users_and_projects_project_scopes_tableId
    ]);

    // error handling
    if(!$patchResult['success']){
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to patch project assigned scopes!',
            'error' => $patchResult['error']
        ], 500);
    }
    else{
        return new WP_REST_Response([
            'success' => true,
            'data' => $patchResult['result'],
            'message' => 'Project Assigned Scope successfully patched!'
        ], 200);
    }
}
function deleteProjectAssignedScopes(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_project_scopes_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$deleteResult = $airtableHelper->deleteRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_project_scopes_tableId
	]);
		
	//error handling
	if(!$deleteResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to delete project assigned scope!', 
			'error' => $deleteResult['error']
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $deleteResult['result'],
			'message' => 'Project Assigned Scope successfully deleted!'
		], 200);
	}
}
function getUserReportScopeProgress(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_userreports_scopeprogress;
	$airtableHelper = new AirtableHelper();
	
    $fetchUserReportScopeProgress = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_userreports_scopeprogress
	]);
	
	//error
	if (!$fetchUserReportScopeProgress['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch user report scope progress!', 
			'error' => $fetchUserReportScopeProgress['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchUserReportScopeProgress['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function createUserReportScopeProgress(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_userreports_scopeprogress;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_userreports_scopeprogress
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload report scope progress!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $uploadResult['result'],
			'message' => 'Report Scope Progress successfully uploaded!'
		], 200);
	}
}
function REDACTED_AIRTABLE_PAT(WP_REST_Request $request) {
    global $users_and_projects_baseId, $users_and_projects_userreports_scopeprogress;
    $body = json_decode($request->get_body(), true);

    if (!$body) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $payload = $body['payload'] ?? null;

    if (empty($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing payload'
        ], 400);
    }

    $patchResult = patchAirtableRecords(
        $users_and_projects_baseId,
        $users_and_projects_userreports_scopeprogress,
        AIRTABLE_API_KEY,
        $payload // can be single OR array
    );

    if (!$patchResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to update user report scope progress',
            'error'   => $patchResult['error'] ?? null
        ], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => $patchResult['records'] ?? [],
        'message' => 'User report scope progress updated successfully'
    ], 200);
}
function deleteUserReportScopeProgress(WP_REST_Request $request) {
    global $users_and_projects_baseId, $users_and_projects_userreports_scopeprogress;
    $body = json_decode($request->get_body(), true);

    if (!$body) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $recordIds = $body['recordIds'] ?? null;

    if (empty($recordIds)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing record IDs'
        ], 400);
    }

    $deleteResult = deleteAirtableRecords(
        $users_and_projects_baseId,
        $users_and_projects_userreports_scopeprogress,
        $recordIds,
        AIRTABLE_API_KEY
    );

    if (!$deleteResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to delete user reports',
            'error'   => $deleteResult['error'] ?? null
        ], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => $deleteResult['response'] ?? [],
        'message' => 'User reports deleted successfully'
    ], 200);
}
function getProjectActionHistory(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_projectactionhistory_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchProjectActionHistory = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projectactionhistory_tableId
	]);
	
	//error
	if (!$fetchProjectActionHistory['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch project action history!', 
			'error' => $fetchProjectActionHistory['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchProjectActionHistory['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function updateProjectActionHistory(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_projectactionhistory_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projectactionhistory_tableId
	]);
		
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload project action history!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		return new WP_REST_Response([ 
			'success' => true,
			'data' => $uploadResult['result'],
			'message' => 'Project Action History uploaded!'
		], 200);
	}
}

//-----------------------PURCHASING----------------------------------------

function createPurchasingAccount(WP_REST_Request $request){
	global $transaction_tracker_baseId,$transaction_tracker_accounts_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$attachmentPayload = $request->get_param('attachment_payload'); // the file data to upload
	$airtableHelper = new AirtableHelper();
	
	//fetch existing accounts
    $fetchExistingAccounts = $airtableHelper->fetchRecords ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_accounts_tableId
	]);
	
	//error
	if (!$fetchExistingAccounts['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch existing accounts!', 
			'error' => $fetchExistingAccounts['error'] // show the real reason 
		], 500);
	}
	else {
		//check for duplicating accounts
		$records = $fetchExistingAccounts['result'];
		if(!empty($records)){
			$accountName = $payload['fields']['Account Name'] ?? null;
			$exists = false;
		
			foreach ($records as $record) {
    			if (!empty($record['fields']['Account Name']) &&
        			$record['fields']['Account Name'] === $accountName
    			) {
        			$exists = true;
        			break;
    			}
			}
			if($exists){
				return new WP_REST_Response([ 
					'success' => false, 
					'message' => 'Account Already Exists!', 
				], 400);
			}
		}
		
		//create a new account
		//upload text data first
		$uploadResult = $airtableHelper->uploadRecords ($payload,[
			'baseId' => $transaction_tracker_baseId,
			'tableId' => $transaction_tracker_accounts_tableId
		]);
		
		//error handling
		if(!$uploadResult['success']){
			return new WP_REST_Response([ 
				'success' => false, 
				'message' => 'Failed to Upload new Purchasing Account!', 
				'error' => $uploadResult['error'] // show the real reason 
			], 500);
		}
		else{
			$userAccount = $uploadResult['result'] ?? null;
			$userAccountRecordId = $uploadResult['result'][0]['id'] ?? null;
			//error handling if it cant find the newly created account
			if(empty($userAccount) || empty($userAccountRecordId)){
				return new WP_REST_Response([ 
					'success' => false, 
					'message' => 'New Account not found!', 
					'error' => $uploadResult['error'], // show the real reason 
					'data' => $uploadResult,
				], 500);
			}
			
			//upload Account QR Code
			$uploadAttachmentResult = $airtableHelper->uploadAttachments ($userAccountRecordId,$attachmentPayload,[
				'baseId' => $transaction_tracker_baseId,
			]);
			
			//error handling in case of failed upload
			if(!$uploadAttachmentResult['success']){
				return new WP_REST_Response([ 
					'success' => false, 
					'message' => 'Failed to Upload Account QR Code!', 
					'error' => $uploadAttachmentResult['error'] // show the real reason 
				], 500);
			}
			else{
				return new WP_REST_Response([ 
					'success' => true, 
					'message' => 'New Purchasing Account Created!'
				], 200);
			}
		}
	}
}
function createEnvelope(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_envelopes_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$airtableHelper = new AirtableHelper();
	
	//fetch existing envelopes
    $fetchExistingEnvelopes = $airtableHelper->fetchRecords ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_envelopes_tableId
	]);
	
	//error
	if (!$fetchExistingEnvelopes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch existing envelopes!', 
			'error' => $fetchExistingEnvelopes['error'] // show the real reason 
		], 500);
	}
	else {
		//check for duplicating accounts
        $records = $fetchExistingEnvelopes['result'];
        if(!empty($records)){
            // Normalize payload to always be an array of records
            $payloadsToCheck = [];
    
            // Check if payload is a single record or multiple records
            if(isset($payload['fields'])) {
                // Single record format: payload has 'fields' key directly
                $payloadsToCheck[] = $payload;
            } else {
                // Multiple records format: payload is an array of records
                $payloadsToCheck = is_array($payload) ? $payload : [$payload];
            }
    
            $duplicates = [];
    
            // Check each payload record
            foreach($payloadsToCheck as $index => $payloadRecord) {
                $envelopeName = $payloadRecord['fields']['Envelope Name'] ?? null;
        
                if(empty($envelopeName)) {
                    continue; // Skip if no envelope name
                }
        
                // Check against existing records
                foreach ($records as $record) {
                    if (!empty($record['fields']['Envelope Name']) && 
                        $record['fields']['Envelope Name'] === $envelopeName) {
                        $duplicates[] = $envelopeName;
                        break;
                    }
                }
            }
    
            if(!empty($duplicates)){
                $message = count($duplicates) === 1 
                    ? 'Envelope Already Exists: ' . $duplicates[0]
                    : 'Envelopes Already Exist: ' . implode(', ', $duplicates);
            
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $message,
                    'duplicates' => $duplicates
                ], 400);
            }
        }
		
		//create a new envelope
		$uploadResult = $airtableHelper->uploadRecords ($payload,[
			'baseId' => $transaction_tracker_baseId,
			'tableId' => $transaction_tracker_envelopes_tableId
		]);
		
		//error handling
		if(!$uploadResult['success']){
			return new WP_REST_Response([ 
				'success' => false, 
				'message' => 'Failed to Upload new Envelopes!', 
				'error' => $uploadResult['error'] // show the real reason 
			], 500);
		}
		else{
			return new WP_REST_Response([ 
				'success' => true, 
				'message' => 'New Envelopes Created!'
			], 200);
		}
	}
}
function getPurchasingAccounts(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_accounts_tableId;
	$airtableHelper = new AirtableHelper();
	
	//fetch existing accounts
    $fetchExistingAccounts = $airtableHelper->fetchRecords ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_accounts_tableId
	]);
	
	//error
	if (!$fetchExistingAccounts['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch existing accounts!', 
			'error' => $fetchExistingAccounts['error'] // show the real reason 
		], 500);
	}
	else {
		//check for duplicating accounts
		$records = $fetchExistingAccounts['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getTransactions(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_transactions_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchTransactions = $airtableHelper->fetchRecords ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_transactions_tableId
	]);
	
	//error
	if (!$fetchTransactions['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch transactions data!', 
			'error' => $fetchTransactions['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchTransactions['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getTransactionTypes(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_transactions_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchTransactionTypes = $airtableHelper->getFieldPossibleValues ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_transactions_tableId,
		'fieldName' => 'Type'
	]);
	
	//error
	if (!$fetchTransactionTypes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch field values data!', 
			'error' => $fetchTransactionTypes['error'] // show the real reason 
		], 500);
	}
	else {
		$values = $fetchTransactionTypes['possibleValues'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $values, 
		], 200);
	}
}
function createTransactions(WP_REST_Request $request){
    global $transaction_tracker_baseId, $transaction_tracker_transactions_tableId;
    $payload = $request->get_param('payload'); // the data to upload
    $attachmentPayload = $request->get_param('attachment_payload'); // array of attachment data
    $airtableHelper = new AirtableHelper();
    
    // Upload text data first (all transactions at once)
    $uploadResult = $airtableHelper->uploadRecords($payload, [
        'baseId' => $transaction_tracker_baseId,
        'tableId' => $transaction_tracker_transactions_tableId
    ]);
    error_log('UPLOAD RESULT: ' . print_r($uploadResult, true)); // DELETE LATER
    
    // Error handling
    if(!$uploadResult['success']){
        return new WP_REST_Response([ 
            'success' => false, 
            'message' => 'Failed to Upload new Transaction Data!', 
            'error' => $uploadResult['error']
        ], 500);
    }
    
    $newTransactionData = $uploadResult['result'] ?? null;
    
    // Error handling if it can't find the newly created transactions
    if(empty($newTransactionData)){
        return new WP_REST_Response([ 
            'success' => false, 
            'message' => 'New Transactions not found!', 
            'error' => $uploadResult['error'],
            'data' => $uploadResult,
        ], 500);
    }
    
    // Upload attachments if they exist
    if(!empty($attachmentPayload)){
        $attachmentErrors = [];
        
        foreach($attachmentPayload as $attachment){
            // Skip if no receipt data
            if(empty($attachment['receiptData'])){
                continue;
            }
            
            $transactionIndex = $attachment['transactionIndex'];
            $receiptData = $attachment['receiptData'];
            
            // Get the corresponding transaction ID
            if(!isset($newTransactionData[$transactionIndex]['id'])){
                $attachmentErrors[] = "Transaction at index {$transactionIndex} not found";
                continue;
            }
            
            $transactionId = $newTransactionData[$transactionIndex]['id'];
            
            // Prepare attachment payload for single transaction
            $singleAttachmentPayload = [
                'Receipt' => [
                    [
                        'filename'      => $receiptData['filename'],
                        'contentType'   => $receiptData['contentType'],
                        'base64Content' => $receiptData['base64Content']
                    ]
                ]
            ];
            
            // Upload attachment for this transaction
            $uploadAttachmentResult = $airtableHelper->uploadAttachments(
                $transactionId,
                $singleAttachmentPayload,
                [
                    'baseId' => $transaction_tracker_baseId,
                    'tableId' => $transaction_tracker_transactions_tableId,
                    'fieldName' => 'Receipt' // Make sure this matches your Airtable field name
                ]
            );
            
            // Track errors but don't stop the process
            if(!$uploadAttachmentResult['success']){
                $attachmentErrors[] = "Failed to upload receipt for transaction {$transactionIndex}: " . $uploadAttachmentResult['error'];
            }
        }
        
        // If there were attachment errors, return them but still mark as success
        if(!empty($attachmentErrors)){
            return new WP_REST_Response([ 
                'success' => true,
                'message' => 'Transactions created but some receipts failed to upload',
                'attachment_errors' => $attachmentErrors
            ], 200);
        }
    }
    
    return new WP_REST_Response([ 
        'success' => true, 
        'message' => 'New Transactions Created!'
    ], 200);
}
function getEnvelopes(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_envelope_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchEnvelopes = $airtableHelper->fetchRecords ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_envelope_tableId
	]);
	
	//error
	if (!$fetchEnvelopes['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch transactions data!', 
			'error' => $fetchEnvelopes['error'] // show the real reason 
		], 500);
	}
	else {
		$records = $fetchEnvelopes['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}
function getEnvelopeGroupChoices(WP_REST_Request $request){
    global $transaction_tracker_baseId,$transaction_tracker_envelope_tableId;
	$airtableHelper = new AirtableHelper();
	
    $fetchEnvelopeGroups = $airtableHelper->getFieldPossibleValues ([
		'baseId' => $transaction_tracker_baseId,
		'tableId' => $transaction_tracker_envelope_tableId,
		'fieldName' => 'Group'
	]);
	
	//error
	if (!$fetchEnvelopeGroups['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch field values data!', 
			'error' => $fetchEnvelopeGroups['error'] // show the real reason 
		], 500);
	}
	else {
		$values = $fetchEnvelopeGroups['possibleValues'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $values, 
		], 200);
	}
}

//-----------------------REIMBURSEMENT----------------------------------------

function createReimbursementRequest(WP_REST_Request $request){
	global $users_and_projects_baseId,$reimbursements_tableId;
    $payload = $request->get_param('payload'); // the data to upload
	$attachmentPayload = $request->get_param('attachment_payload'); // the file data to upload
	$airtableHelper = new AirtableHelper();
	
	//create a new account
	//upload text data first
	$uploadResult = $airtableHelper->uploadRecords ($payload,[
		'baseId' => $users_and_projects_baseId,
		'tableId' => $reimbursements_tableId
	]);
	
	//error handling
	if(!$uploadResult['success']){
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to upload initial data!', 
			'error' => $uploadResult['error'] // show the real reason 
		], 500);
	}
	else{
		$reimbursement = $uploadResult['result'] ?? null;
		$reimbursementRecordId = $uploadResult['result'][0]['id'] ?? null;
		//error handling if it cant find the newly created account
		if(empty($reimbursement) || empty($reimbursementRecordId)){
			return new WP_REST_Response([ 
				'success' => false, 
				'message' => 'reimbursement record not found!', 
				'error' => $uploadResult['error'], // show the real reason 
				'data' => $uploadResult,
			], 500);
		}
		
		//upload Account QR Code
		$uploadAttachmentResult = $airtableHelper->uploadAttachments ($reimbursementRecordId,$attachmentPayload,[
			'baseId' => $users_and_projects_baseId,
		]);
		
		//error handling in case of failed upload
		if(!$uploadAttachmentResult['success']){
			return new WP_REST_Response([ 
				'success' => false, 
				'message' => 'Failed to Upload Reimbursement Receipts!', 
				'error' => $uploadAttachmentResult['error'] // show the real reason 
			], 500);
		}
		else{
			return new WP_REST_Response([ 
				'success' => true, 
				'message' => 'reimbursement request submitted!'
			], 200);
		}
	}
}
function REDACTED_AIRTABLE_PAT(WP_REST_Request $request) {
	global $users_and_projects_baseId,$reimbursements_tableId;
	$recordId = $request['id'];
	if (empty($recordId)) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Missing record ID'
		], 400);
	}

	// Read RAW JSON body
	$body = json_decode($request->get_body(), true);

	if (!$body) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Invalid JSON body'
		], 400);
	}

	$payload = $body['payload'] ?? null;
	$attachmentPayload = $body['attachment_payload'] ?? null;

	// Update fields (Status)
	if (!empty($payload['fields'])) {
		$patchResult = patchAirtableRecords(
			$users_and_projects_baseId,
			$reimbursements_tableId,
			AIRTABLE_API_KEY,
			[
				'id' => $recordId,
				'fields' => $payload['fields']
			]
		);

		if (!$patchResult['success']) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Failed to update reimbursement status',
				'error'   => $patchResult['error'] ?? null
			], 500);
		}
	}

	// Upload receipt
	if (!empty($attachmentPayload)) {
	$airtableHelper = new AirtableHelper();

	$uploadResult = $airtableHelper->uploadAttachments(
		$recordId,
		[
			'fields' => [
				$attachmentPayload['fieldName'] => [
					[
						'filename' => $attachmentPayload['file']['filename'],
						'contentType' => $attachmentPayload['file']['contentType'],
						'base64Content' => $attachmentPayload['file']['base64']
					]
				]
			]
		],
		['baseId' => $users_and_projects_baseId]
	);

	if (!$uploadResult['success']) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Failed to upload reimbursement receipt',
			'error'   => $uploadResult['error'] ?? null
		], 500);
	}
}

	return new WP_REST_Response([
		'success' => true,
		'message' => 'Reimbursement updated successfully'
	], 200);
}
function getReimbursementRecords(WP_REST_Request $request){
    global $users_and_projects_baseId,$reimbursements_tableId;
	$airtableHelper = new AirtableHelper();
	
	//fetch existing accounts
    $fetchReimbursementRecords = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $reimbursements_tableId
	]);
	
	//error
	if (!$fetchReimbursementRecords['success']) {
		return new WP_REST_Response([ 
			'success' => false, 
			'message' => 'Failed to fetch reimbursement records!', 
			'error' => $fetchReimbursementRecords['error'] // show the real reason 
		], 500);
	}
	else {
		//check for duplicating accounts
		$records = $fetchReimbursementRecords['result'];
		return new WP_REST_Response([ 
		    'success' => true, 
			'data' => $records, 
		], 200);
	}
}