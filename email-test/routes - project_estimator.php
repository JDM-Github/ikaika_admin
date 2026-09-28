<?php
namespace portalikaikabim\routes\portalapps\projectEstimator;
use portalikaikabim\services\projectEstimator\AirtableHelper;
use portalikaikabim\services\projectEstimator\MailService;
use portalikaikabim\services\projectEstimator\msTeams\MsGraphService;
use portalikaikabim\services\projectEstimator\msTeams\MsTeamsMeetingsService;
use portalikaikabim\services\projectEstimator\msTeams\MsTeamsAttendanceService;
use portalikaikabim\services\projectEstimator\msTeams\MsTeamsHelper;
use WP_REST_Request,WP_REST_Response;
use Exception;

// Every response from this API is dynamic, per-caller (Bearer token) data - the host's page cache
// (Bluehost Endurance Cache) must never store/replay it, or one caller's response (e.g. /currentUser,
// which takes no query params at all) can leak to a completely different caller within the cache window.
// DONOTCACHEPAGE is the standard signal Endurance Cache (and most other WP cache plugins) checks.
if (!defined('DONOTCACHEPAGE') && strpos($_SERVER['REQUEST_URI'] ?? '', '/project_estimator/') !== false) {
    define('DONOTCACHEPAGE', true);
}
// belt-and-suspenders for any reverse-proxy/nginx cache layer that doesn't consult DONOTCACHEPAGE:
// explicitly tell it not to cache. Runs for every normal (WP_REST_Response) route in this namespace;
// /files/download sends the same headers itself since it exits before this filter would ever run.
add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
    global $pe_rest_namespace;
    if (strpos($request->get_route(), '/' . $pe_rest_namespace . '/') === 0) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');

        $config = versionConfig();
        // always advertised, so a client can record which API it actually talked to without asking
        header('X-Api-Version: ' . $config['apiRelease']);

        // Version advisory, reported as HEADERS rather than injected into the JSON body: a new body key
        // could break a client that deserializes strictly, whereas an unread header cannot break anything.
        // Nothing is refused here - see the gate filter for the opt-in 'block' mode.
        if ($config['mode'] !== 'off') {
            $client = clientVersionStatus($request);
            $api = apiVersionStatus($request);
            $band = worstVersionBand([$client['band'], $api['band']]);

            if (in_array($band, ['danger', 'warn', 'unknown'], true)) {
                header('X-Version-Status: ' . $band);
                header('X-Client-Version-Band: ' . $client['band'] . '; min=' . $client['min'] . '; recommended=' . $client['recommended']);
                header('X-Api-Version-Band: ' . $api['band'] . '; min=' . $api['min'] . '; recommended=' . $api['recommended']);
            }
        }
    }
    return $served;
}, 10, 4);

// MAINTENANCE GATE: runs before any handler in this namespace and short-circuits with 503 when the
// matching toggle is on. Registered here rather than in each permission_callback so it covers public
// routes and write routes uniformly, and so no future route can forget to check it.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    global $pe_rest_namespace;
    $route = $request->get_route();
    if (strpos($route, '/' . $pe_rest_namespace . '/') !== 0) {
        return $result; // not ours - leave every other plugin's routes alone
    }

    $method = $request->get_method();
    $path = substr($route, strlen('/' . $pe_rest_namespace));

    // VERSION FLOOR, only in 'block' mode and only for the 'danger' band - a caller that actually declared
    // a version below the floor. 'warn' is never refused, and 'unknown' (no header at all) never is either.
    // Exempt routes are skipped so /ping keeps answering and the operator surface stays reachable: that is
    // both how a refused client discovers WHY, and how a mistyped floor gets corrected.
    if (versionConfig()['mode'] === 'block' && !in_array($path, gateExemptRoutes(), true)) {
        $client = clientVersionStatus($request);
        $api = apiVersionStatus($request);
        $offender = ($client['band'] === 'danger') ? $client : (($api['band'] === 'danger') ? $api : null);

        if ($offender !== null) {
            $isClient = $client['band'] === 'danger';
            // 426 Upgrade Required is the accurate code: the request is fine, the client is not
            return new WP_REST_Response([
                'success' => false,
                'message' => $isClient
                    ? "This app version is no longer supported. Please update to {$offender['min']} or later."
                    : "This app was built against an unsupported version of the API. Please update to a build targeting {$offender['min']} or later.",
                'error' => 'client_too_old',
                'data' => [
                    'failed' => $isClient ? 'clientVersion' : 'apiVersion',
                    'minVersion' => $offender['min'],
                    'yourVersion' => $offender['sent']
                ]
            ], 426);
        }
    }

    // MAINTENANCE first: a service that is deliberately down outranks one that is merely busy, so a 503
    // must win over a 429. State is read once and handed to the classifier - this filter runs on every
    // request in the namespace, so it must not cost two option reads per call.
    $state = maintenanceState();
    $blocked = maintenanceBlocks($route, $method, $state);
    if ($blocked !== null) {
        $response = new WP_REST_Response([
            'success' => false,
            'message' => $state['message'] !== '' ? $state['message'] : 'The service is temporarily unavailable for maintenance.',
            'error' => 'maintenance_mode',
            'data' => ['blocked' => $blocked]
        ], 503);
        // tells a well-behaved client how long to wait instead of hammering the endpoint
        $response->header('Retry-After', (string) max(1, (int) $state['retryAfterSeconds']));
        return $response;
    }

    // THROTTLE: a broad per-caller budget on top of the endpoint-specific buckets. Both default to max 0
    // (off), so unless an operator sets one this whole block is a single option read and nothing else - no
    // transient write on the hot path. $path was resolved at the top of this filter.
    if (in_array($path, gateExemptRoutes(), true)) {
        return $result;
    }

    $bucket = gateRateBucket($path, $method);
    if ($bucket === null || (int) rateLimitConfig($bucket)['max'] <= 0) {
        return $result;
    }

    $identifier = rateLimitIdentifier($bucket, $request);
    if (isRateLimited($bucket, $identifier)) {
        return rateLimitedResponse($bucket, $identifier, $bucket === 'read'
            ? 'Too many requests. Slow down and try again shortly.'
            : 'Too many changes in a short time. Try again shortly.');
    }

    // counted before the handler runs, so the budget covers every request that reaches the API rather
    // than only the ones that succeed - which is what makes it a rate limit and not a success quota
    registerRateLimitHit($bucket, $identifier);
    return $result;
}, 10, 3);

//------------------------------------------------------------------------------------
//                            REST API REGISTRATION
//------------------------------------------------------------------------------------
//----------------------CONNECTION TEST--------------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/ping', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\pingConnection',
        'permission_callback' => '__return_true'
    ]);
});

add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/currentUser', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getCurrentUser',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------TIME MONITORING-------------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/tm/activitycodes', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getActivityCodes',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});


//----------------------PROJECT MANAGEMENT----------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/pm/projects', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getProjects',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/pm/reports', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserReports',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------USERS-------------------------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/user/region', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getUserRegions',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------CALENDAR------------------------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/calendar/holidays', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getHolidays',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------PROJECT ESTIMATES--------------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/estimates/create', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\createProjectEstimate',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/estimates/update', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateProjectEstimate',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/estimates/delete', [
        'methods'  => 'DELETE',
        'callback' => __NAMESPACE__ . '\\deleteProjectEstimate',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/estimates/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listMyEstimates',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/estimates/share', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\shareProjectEstimate',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------DEVICE MANAGEMENT (THE USER'S OWN SESSIONS)-------------------------------
// Bearer-gated, unlike the operator routes below: a user manages their own devices, an operator manages
// everyone's. Scoped to the caller's session recordId, never to an id from the request.
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/session/mine', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listMySessions',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
	register_rest_route($pe_rest_namespace, '/session/mine/terminate', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\terminateMySessions',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------SESSION MANAGEMENT / MAINTENANCE (OPERATOR)-------------------------------
// permission_callback is __return_true on every route below because the shared-key check lives in the
// handler: it has to return this API's {success,message} envelope with a 401/503, not WordPress's own
// rest_forbidden shape, and it has to register a rate-limit failure on a wrong key.
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/session/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listSessions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/session/terminate', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\terminateSessions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/session/settings', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getSessionSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/session/settings', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateSessionSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/maintenance', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getMaintenance',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/maintenance', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateMaintenance',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/ratelimits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getRateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/ratelimits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateRateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/versions', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getVersions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/versions', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateVersions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/estimates/limits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEstimateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/estimates/limits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateEstimateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/bookings/limits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getBookingLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/bookings/limits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateBookingLimits',
        'permission_callback' => '__return_true'
    ]);
	// The client-readable half of the pair above. Bearer, not the operator key: the app
	// cannot hold PROJEST_SESSION_KEY, so without this the only way a client ever learns
	// the booking limit is by being refused with it (409 booking_limit) - and that check
	// is log-only today, so in practice it never learns it at all.
	register_rest_route($pe_rest_namespace, '/bookings/policy', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getBookingPolicy',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
	register_rest_route($pe_rest_namespace, '/bugs/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listBugReports',
        'permission_callback' => '__return_true'
    ]);

    // /admin/* - added 2026-08-11, alongside the routes above rather than in place of them. Each one is a
    // pure alias: same handler function, same session-key gate, same behaviour - only the path is new. The
    // originals are not removed and have no removal date; deprecation-first, per CLAUDE.md's API rules.
    // "Session management is now an admin control panel" - the naming this migration exists to catch up to.
	register_rest_route($pe_rest_namespace, '/admin/sessions/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listSessions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/sessions/terminate', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\terminateSessions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/sessions/settings', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getSessionSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/sessions/settings', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateSessionSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/maintenance', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getMaintenance',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/maintenance', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateMaintenance',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/ratelimits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getRateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/ratelimits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateRateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/versions', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getVersions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/versions', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateVersions',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/estimates/limits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEstimateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/estimates/limits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateEstimateLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/bookings/limits', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getBookingLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/bookings/limits', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateBookingLimits',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/teams/settings', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getTeamsSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/teams/settings', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\updateTeamsSettings',
        'permission_callback' => '__return_true'
    ]);
	register_rest_route($pe_rest_namespace, '/admin/bugs/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\listBugReports',
        'permission_callback' => '__return_true'
    ]);
});

//----------------------PROJECT ESTIMATOR FILES-------------------------------
// TEMPORARY MIGRATION - fixed Project Estimates records that predated the ownership feature (no/wrong
// owner marker); the normal endpoints can't touch these by design, since ownership denies everyone.
// Route disabled now that the affected records (2026-JUL-27) are cleaned up - the function below is
// left in place in case another legacy/orphaned record turns up; uncomment to reuse.
/*
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/admin/fix-estimates', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\fixLegacyEstimates',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
*/

add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/files/list', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getEstimateFiles',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/files/download', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\downloadEstimateFile',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/files/upload', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\uploadEstimateFile',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
	register_rest_route($pe_rest_namespace, '/bugs/report', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\reportBug',
        'permission_callback' => function (WP_REST_Request $request) {
			// filing is not an admin action - any authenticated app user may report a bug
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/files/delete', [
        'methods'  => 'DELETE',
        'callback' => __NAMESPACE__ . '\\deleteEstimateFile',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//----------------------ACCOUNT MANAGEMENT-----------------------------------
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/auth/login', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\loginUser',
        'permission_callback' => '__return_true'
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/auth/logout', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\logoutUser',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/account/create', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\addUserAccount',
        // token, or the first account bootstrap (invitees register via /account/redeem instead)
        'permission_callback' => __NAMESPACE__ . '\\canCreateAccount'
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/account/redeem', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\redeemAccountInvite',
        // public: the invitee supplies a scanned InviteCode plus their own chosen credentials
        'permission_callback' => '__return_true'
    ]);
});
// DEPRECATED, kept for the existing frontend - see POST /account/password below. Both remain registered
// until nothing calls this one; removing it is a separate, deliberate change.
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/account/modify', [
        'methods'  => 'PATCH',
        'callback' => __NAMESPACE__ . '\\changeUserPassword',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
	register_rest_route($pe_rest_namespace, '/account/password', [
        'methods'  => 'POST',
        'callback' => __NAMESPACE__ . '\\changeAccountPassword',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/account/delete', [
        'methods'  => 'DELETE',
        'callback' => __NAMESPACE__ . '\\deleteUserAccount',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
// TEMPORARY DEBUG - confirms the server PAT can read the accounts table; comment out after diagnosing.
// RETIRED per ADR 0018, and verified rest_no_route on production and staging on 2026-08-27.
//
// DO NOT UNCOMMENT THIS AS IT STANDS. Its permission_callback is '__return_true', so re-enabling it
// publishes every account's Password hash and permanent InviteCode to anyone who knows the path - it is
// the one temporary endpoint in this file that was never bearer-gated. Gate it first, or read the table
// through .\testing.ps1 airtable, which needs a PAT nobody outside the team has.
/*
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/debug/accounts', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\debugReadAccounts',
        'permission_callback' => '__return_true'
    ]);
});
*/

//----------------------MICROSOFT TEAMS---------------------------------------
// Both GET, so gateRateBucket() puts them in the 'read' bucket with every other read, and neither is
// in gateExemptRoutes() - Teams data is ordinary application data, so maintenance mode blocks it like
// the rest. Bearer token, same as every other data route: this is app data, not an operator control.
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/teams/meetings', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getTeamsMeetings',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});
add_action('rest_api_init', function () {
	global $pe_rest_namespace;
	register_rest_route($pe_rest_namespace, '/teams/attendance', [
        'methods'  => 'GET',
        'callback' => __NAMESPACE__ . '\\getTeamsAttendance',
        'permission_callback' => function (WP_REST_Request $request) {
			// 1. Allow authenticated users via bearer token
    		if (validateAuthToken($request)) {
        		return true;
    		}
    		return false;
        }
    ]);
});

//-------------------------------------------------------------------------------------
//								GLOBALS
//-------------------------------------------------------------------------------------
// REST routes are registered under api/{version}/{name}
$pe_api_version = 'v0';
$pe_api_name = 'project_estimator';
$pe_rest_namespace = 'api/' . $pe_api_version . '/' . $pe_api_name;

// MICROSOFT TEAMS - the account whose meetings and attendance may be read. Every Graph call is
// user-scoped (/users/{id}/...) because application permissions cannot use /me, and the tenant's Teams
// application access policy has to name this same account, so the two must agree.
// EMPTY means the caller must pass ?organizer=: a deploy that has not been told which account to use
// asks for one rather than guessing at somebody's mailbox.
$pe_msteams_organizer = '';

// The window GET /teams/meetings reads when the caller sends no start/end. Thirty days each
// way, because a meeting list is asked for in both directions: what happened and what is
// coming. Both are retunable at runtime through PATCH /admin/teams/settings, so a tenant
// with a busier calendar can be narrowed without a redeploy - same arrangement as
// $pe_max_concurrent_bookings and its /admin/bookings/limits override.
$pe_msteams_range_past_days = 30;
$pe_msteams_range_future_days = 30;
$pe_msteams_settings_option = 'pe_msteams_settings';

// WP transient key prefixes
$pe_auth_transient_prefix = 'pe_auth_';
// shared by the generic per-IP rate limiter (isRateLimited/registerRateLimitFailure/clearRateLimitFailures), namespaced by bucket
$pe_rate_limit_transient_prefix = 'pe_ratelimit_';

// AIRTABLE BASE ID
$users_and_projects_baseId = 'appgh0Mki2uHDLAQY'; // update: 2026-JUL-22

// PROJECTS TABLE
$users_and_projects_projects_tableId = 'tblGcioPZTKFkuH5q'; // update: 2026-JUL-22

// ACTIVITY CODES TABLE
$users_and_projects_activitycodes_tableId = 'tbl2pBn659tBJOopl'; // update: 2026-JUL-22

// USER REPORTS TABLE
$users_and_projects_userreports_tableId = 'tbliMrRnGYwhRIUqS'; // update: 2026-JUL-22

// GRID VIEW - ACTIVE STATUS TABLE
$active_status_table_id = 'tbltDzOjQKkh7IsQf'; // update: 2026-JUL-23
$active_status_id_number_column_id = 'fld1H4xvaNXDg7GlR'; // update: 2026-JUL-23 - specific field ID for the "ID Number" column in the Active Status table
$active_status_location_column_id = 'fld0X7E9GamwaTAaP'; // update: 2026-JUL-23 - specific field ID for the "Location" column in the Active Status table
// Name and Email, for resolving the people named in an estimate's Member Trades into addresses.
// THESE TWO AND NOTHING ELSE. The same table also holds bank account numbers, tax / PhilHealth / SSS /
// HDMF identifiers, dates of birth, home addresses, personal e-mail and emergency contacts. Naming the
// columns here and passing them as Airtable's fields[] is what keeps every one of those out of this
// process, out of error_log, and out of the estimates table - a read without fields[] would pull all 45.
$active_status_name_column = 'Name';   // fldt3Fp7so31cGqvT
$active_status_email_column = 'Email'; // fldcUPxigbzjNgopE

// HOLIDAY TABLE
$holiday_table_id = 'tblhGH9QUfJ61BVyg'; // update: 2026-JUL-23

// PROJECT ESTIMATES TABLE
$project_estimates_table_id = 'tbly18RtAuy8Vyn8t'; // update: 2026-JUL-24
$project_estimates_attachments_column_id = 'fldFYnlJOQO3Ge6cJ'; // update: 2026-JUL-24 - specific field ID for the "Attachments" column
// The "Date Modified" column, ISO 8601 UTC. Written by this API on every create, update and file change.
// The format is chosen so that a lexicographic sort equals a chronological one - a plain text column gives
// no ordering of its own, so the value has to carry it. Empty disables the column entirely: it is then
// neither read nor written, and /estimates/list falls back to each record's createdTime.
//
// Plain text rather than Airtable's computed "Last modified time" because that field type cannot be created
// through the API at all (422 UNSUPPORTED_FIELD_TYPE_FOR_CREATE). The tradeoff is real: only this API
// maintains it, so an edit made by hand in the Airtable base does NOT bump it. To switch later, add a
// computed column by hand, point this at it, and delete the withModifiedStamp() calls - the read side needs
// no change at all.
$project_estimates_modified_column = 'Date Modified'; // fldRipxav6CCroKbV, created 2026-AUG-11

// How many estimates one account may own with a Status other than Done. 0 = no cap, matching the
// rate-limit buckets' convention, and it is the shipped default deliberately: the cap costs an extra
// Airtable count on every create, so while it is 0 that query never runs and /estimates/create behaves
// exactly as it did before this existed. Raise it from PATCH /estimates/limits once a real number is known.
$pe_max_active_estimates = 0;
$pe_estimate_limits_option = 'pe_estimate_limits';

// Booking/conflict detection - docs/proposals/server-side-booking-and-conflict-detection.md, approved
// 2026-08-11. Projects three fields out of the client's estimate.json snapshot into real Airtable columns,
// so the server can answer "is this member already booked" without downloading and parsing every
// attachment - which is structurally all it could do before these existed.
$project_estimates_start_column        = 'Project Start';   // fldISCB934OhECFAy, created 2026-AUG-11
$project_estimates_end_column          = 'Project End';     // fldS48009olxOkhNT, created 2026-AUG-11
$project_estimates_membertrades_column = 'Member Trades';   // fldlXcMbh0kv1vgUF, created 2026-AUG-11

// Derived from the three columns above: who is on this project, and when. Both are PROJECTIONS and nothing
// in this file reads them back, so a stale value is only ever stale - never a wrong answer to a question
// this API asks. Empty disables that column entirely, the same idiom as the three above and as Date
// Modified, which is what makes them safe to add before the columns exist in the base.
//
// Member Emails mirrors Member Trades key for key. Booking Schedule repeats those addresses next to the
// dates so one column can be handed to a scheduling call without joining it back - the duplication is
// deliberate and is the whole point of that column.
$project_estimates_memberemails_column = 'Member Emails';    // fldsW2bXujqFh38sG, created 2026-AUG-27
$project_estimates_schedule_column     = 'Booking Schedule'; // fldvFb2zhPTa6qQbl, created 2026-AUG-27

// The staff directory behind Member Emails is cached: withBookingProjection() runs on every
// /files/upload, and re-reading the staff table per upload would add an Airtable round-trip to that path
// for data that changes when somebody is hired. An hour is far shorter than that.
$pe_member_email_transient = 'pe_member_emails';
$pe_member_email_ttl_seconds = 3600;

// How many overlapping bookings one member (identified by name - proposal S3.2, member identity stays a
// string for now) may hold before a save is refused. 2 matches the client's own compiled-in
// EstimatorConstants.maxConcurrentProjectsPerMember, so the server starts out AGREEING with the app rather
// than at a default of "no cap". Mode starts at 'warn': the check runs and logs on every /files/upload but
// never refuses, so bookings already over the limit surface in the log before anything blocks - the
// rollout the proposal itself asked for. Raise via PATCH /bookings/limits once the log is clean.
$pe_max_concurrent_bookings = 2;
$pe_booking_limits_option = 'pe_booking_limits';
$pe_booking_enforce_mode = 'warn'; // off | warn | block

// How long an estimates write waits for the advisory lock that serialises the read-modify-write
// sequences in this file, in seconds. An Airtable round-trip is roughly 200-500ms, so this leaves room
// for a few queued writers before giving up and proceeding unlocked.
$pe_lock_timeout_seconds = 5;

// dedicated project_estimator accounts table: fields are Name, Username, Password (hashed), InviteCode (permanent, system-generated), InvitedBy (inviter/creator "Name::username", system-stamped)
$project_estimator_accounts_baseId = 'appgh0Mki2uHDLAQY'; // TODO: set Airtable base ID
$project_estimator_accounts_tableId = 'tbls00i5EQPpgRPb9'; // TODO: set Airtable table ID

// BUG REPORTS TABLE - created 2026-AUG-11. Columns: Title (primary), Description, Severity, Status,
// Reporter (the reporter's Accounts record id, not their name - an admin resolves it against Accounts,
// so this table never becomes a second copy of anyone's personal details), Client Version, API Version,
// Device. Airtable supplies createdTime on every record for free, so there is no timestamp column.
$project_estimator_bugreports_tableId = 'tbltXTfNs9mtOuHIl'; // update: 2026-AUG-11

// STAGING ONLY: when true, /account/create needs no token/invite; set false before production
$pe_open_account_create = false; // default: false

// /account/redeem: wrong-InviteCode attempts allowed per IP before a temporary lockout
$pe_redeem_max_attempts = 5;
$pe_redeem_lockout_minutes = 1; // default: 5

// /auth/login: wrong-password attempts allowed per IP before a temporary lockout
$pe_login_max_attempts = 5;
$pe_login_lockout_minutes = 1; // default: 15

// How long a bearer token stays valid after login, in SECONDS. Days until 2026-08-02, minutes until
// 2026-08-05, now seconds - which is what set_transient() takes natively, so this is the last unit change.
// 86400 = 1 day, and is exactly what the previous 1440 minutes meant: the unit changed, the value did not.
// Handy values: 1800 = 30 minutes, 28800 = an 8-hour shift, 604800 = 1 week.
$pe_auth_token_ttl_seconds = 86400;

// EMAIL - the From address is fixed here and is never client-settable, so no caller can send mail as
// someone else. Reply-To must be a real monitored mailbox: noreply@ cannot receive replies, and the
// accounts table has no per-user email field to fall back on, so replies have nowhere else to land.
$pe_email_from = 'IKAIKABIM <noreply@ikaikabim.com>';
$pe_email_reply_to = 'info@ikaikabim.com'; // TODO: confirm this mailbox exists and someone reads it

// /estimates/share limits - recipients per request, and messages per account per window. Counted per
// MESSAGE, not per request, since messages are what spend the domain's sending reputation.
$pe_share_max_recipients = 5;
$pe_share_max_messages = 20;
$pe_share_window_minutes = 60;
$pe_share_max_note_length = 1000;
$pe_share_max_attachment_bytes = 5242880; // 5 MB total, before base64 inflates it by roughly a third

// VERSIONING. Both the client app and this API are versioned Major.Minor.Patch+Build (Flutter pubspec
// style, e.g. 1.4.2+87), and both are checked the same way, through the same band evaluator. Build is the
// final tiebreaker rather than ignored the way strict semver would, because it increments per release.
//
// Two independent checks, each with THREE BANDS rather than a single pass/fail line:
//   good    - at or above the recommended version. Nothing reported.
//   warn    - supported, but behind. Reported to the caller; never refused.
//   danger  - below the floor. Refused only in 'block' mode.
// Plus two non-judgements: 'unknown' (the caller sent nothing usable) and 'disabled' (no thresholds set).
//
// The mode ladder is what keeps a floor from being a breaking change:
//   'off'   - not checked.
//   'warn'  - DEFAULT. Advisory only, via response headers. Cannot refuse anybody.
//   'block' - 'danger' callers get 426. A scheduled breaking change, not a config tweak.
//
// A caller sending NO version is NEVER refused, in any mode. Every build shipped before this existed sends
// nothing, so refusing those would break the whole installed fleet on upload.
//
// These are FILE DEFAULTS. Every one is overridable at runtime through PATCH /versions, so thresholds can
// be moved without an upload - see versionConfig().
$pe_versions_option = 'pe_versions';

// This API's own version. Bump it per deploy. The 'v2' in the route namespace is a separate, coarser thing:
// it changes only on a wholesale incompatible redesign, whereas this moves every release.
$pe_api_release = '2.0.0+1';

// Client app thresholds. Both EMPTY means the client check is inert - fill in when you know your fleet.
$pe_client_min_version = '';         // below this = danger, e.g. '1.4.2+87'
$pe_client_recommended_version = ''; // below this (but at/above min) = warn
$pe_client_version_header = 'X-Client-Version';

// Which API version a client declares it was built against, so an old client talking to a newer API is
// visible before it misbehaves. Empty means inert.
$pe_api_min_version = '';         // below this = danger
$pe_api_recommended_version = ''; // below this = warn
$pe_api_version_header = 'X-Api-Version';

$pe_version_mode = 'warn'; // 'off' | 'warn' | 'block'

// The PDF report the CLIENT hands to /estimates/share. It is rendered from the estimate JSON on the device
// and never stored server-side, so this is a per-request limit on what one email may carry, not on storage.
$pe_share_max_pdf_bytes = 5242880; // 5 MB, decoded

// OPERATOR ENDPOINTS (/session/*, /maintenance) - gated by a single shared key, NOT by a bearer token.
// The key itself is the PHP constant named below, defined in wp-config.php outside this repo exactly like
// PROJEST_API_KEY. It is deliberately not a global here: a literal would be committed to git.
// If the constant is undefined every operator endpoint returns 503 and denies - fail closed, never open.
$pe_session_key_constant = 'PROJEST_SESSION_KEY';
$pe_session_key_header = 'X-Session-Key'; // a header, not ?key= - query strings land in server access logs
$pe_session_max_attempts = 5;
$pe_session_lockout_minutes = 15; // longer than /auth/login: one shared key is worth more to a guesser

// SESSION IP LOCK - if the IP a token is used from stops matching the IP it was issued to, the session is
// destroyed on the spot and the caller gets 401. It turns a stolen token into a token that only works from
// the thief's own network, which is a real narrowing.
//
// The cost is spurious logouts, and on a phone that cost is not small: Wi-Fi to cellular, a tower handoff
// while walking, or a carrier reassigning a CGNAT address all change REMOTE_ADDR without anything being
// wrong. Hence three modes rather than a boolean, and an operator override via PATCH /session/settings so
// the mode can be dialled back in one call instead of a cPanel upload:
//   'exact'  - REMOTE_ADDR must match byte for byte. Strictest, and the most spurious logouts.
//   'subnet' - same /24 (IPv4) or /64 (IPv6). Tolerates reassignment inside one network, still catches a
//              token turning up on another continent. A reasonable middle for mobile clients.
//   'off'    - no check; behaviour as it was before this existed.
// Default 'subnet' rather than 'exact' on purpose: exact ends a session on any address change at all, which
// on a phone happens for entirely innocent reasons several times a day. Subnet keeps the security value
// (a token surfacing on another network dies) without the constant mid-task logouts.
$pe_session_ip_lock = 'subnet'; // 'exact' | 'subnet' | 'off'

// WP options (not globals) so both survive a page load and can be changed without a cPanel re-upload
// Session index, stored one option row per account as 'pe_sessions_<recordId>' plus a registry of which
// accounts have rows. See the SESSION INDEX block for why it is per-account rather than one shared row.
$pe_session_option_prefix = 'pe_sessions_';
$pe_session_accounts_option = 'pe_session_accounts';
// DEPRECATED single shared row, superseded by the per-account rows above. Migrated into them once on the
// next login and then left in place rather than deleted - remove it by hand once you are satisfied.
$pe_session_index_option = 'pe_session_index';
$pe_session_index_migrated_option = 'pe_session_index_migrated'; // so the migration runs once, not per login
$pe_session_settings_option = 'pe_session_settings'; // operator overrides, currently just the token TTL
$pe_maintenance_option = 'pe_maintenance'; // the maintenance toggles
$pe_rate_limits_option = 'pe_rate_limits'; // per-bucket rate limit overrides; see rateLimitDefaults()
// Devices (sessions) kept per account, oldest evicted first. Per account rather than global, so nobody
// else's logins can drop a device from your own list. 8 is generous for an internal tool.
$pe_session_max_devices = 8;

//--------------------------------------------------------------------------------------
//										ACTIONS
//--------------------------------------------------------------------------------------

//-----------------------------SHARED HELPERS------------------------------------------- keep
// Optional row limit for testing (the app's developer-only Advanced settings screen), honored by every
// list endpoint under the same name: ?limit=N returns at most N rows. Absent = unchanged behavior, i.e.
// the full live set, so nothing changes for normal callers. Endpoints that return a single object or
// write data ignore it entirely, which is what lets the client append it to every request blindly.
// Returns ['ok' => true, 'limit' => int|null], or ['ok' => false, 'response' => WP_REST_Response].
function parseRowLimit(WP_REST_Request $request) {
    $limit = $request->get_param('limit');

    if ($limit === null || $limit === '') {
        return ['ok' => true, 'limit' => null];
    }
    // reject junk instead of ignoring it - a mistyped limit must be obvious, not silently unlimited
    if (!is_scalar($limit) || preg_match('/^\d+$/', (string) $limit) !== 1 || (int) $limit < 1) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'limit must be a positive integer - omit it entirely for the full set'
        ], 400)];
    }

    return ['ok' => true, 'limit' => (int) $limit];
}

// Which columns a list endpoint should return, or null for "every column the table has".
//
// This is a DEPRECATION, not a feature. Omitting it is what these endpoints have always done and what
// they will stop doing: Projects is 82 columns and User Reports is 25 - per-employee hours, remarks and
// approvals - and every caller receives all of them whether it reads two or twenty. The parameter exists
// so a client can narrow FIRST; the wide default stays until docs/api-deprecations.md says otherwise,
// because removing it without warning is exactly the break this repo's API rules forbid.
//
// Junk is a 400 rather than a silent fallback, the same choice parseRowLimit makes: a mistyped column
// name that quietly returned everything would read as "the parameter does nothing".
function parseFieldList(WP_REST_Request $request) {
    $fields = $request->get_param('fields');

    if ($fields === null || $fields === '') {
        return ['ok' => true, 'fields' => null, 'narrowed' => false];
    }

    if (is_string($fields)) {
        $fields = explode(',', $fields);
    }
    if (!is_array($fields)) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'fields must be a comma-separated list of column names - omit it entirely for every column'
        ], 400)];
    }

    $wanted = [];
    foreach ($fields as $name) {
        if (!is_scalar($name)) {
            continue;
        }
        $name = trim((string) $name);
        if ($name !== '' && !in_array($name, $wanted, true)) {
            $wanted[] = $name;
        }
    }

    if (empty($wanted)) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'fields was given but named no columns - omit it entirely for every column'
        ], 400)];
    }

    return ['ok' => true, 'fields' => $wanted, 'narrowed' => true];
}

// Says on the response itself that the wide default is going away. A deprecation recorded only in a file
// is one nobody calling the API ever sees, and these callers are the ones who have to act on it.
function withFieldsDeprecation(WP_REST_Response $response, array $fieldList, $columnCount) {
    if (empty($fieldList['narrowed'])) {
        $response->header('X-Deprecation', 'fields');
        $response->header('X-Deprecation-Notice',
            "returning all {$columnCount} columns is deprecated - pass ?fields=a,b,c; see docs/api-deprecations.md");
    }
    return $response;
}

// Query-string booleans arrive as strings, so a bare cast would read 'false' and '0' as true. Anything
// unrecognised is a 400 rather than a silent fallback: a mistyped flag that quietly reverts to the default
// surfaces later as "why am I still getting global estimates", with nothing to point at. $names is tried in
// order so an endpoint can accept both a camelCase and a hyphenated spelling of the same flag.
function parseBoolParam(WP_REST_Request $request, array $names, $default) {
    foreach ($names as $name) {
        if (!$request->has_param($name)) {
            continue;
        }
        $value = $request->get_param($name);
        if (is_bool($value)) {
            return ['ok' => true, 'value' => $value, 'sentAs' => $name];
        }

        $normalised = strtolower(trim((string) $value));
        // ?flag= with nothing after it is treated as absent, matching how parseRowLimit reads an empty limit
        if ($normalised === '') {
            continue;
        }
        if (in_array($normalised, ['1', 'true', 'yes'], true)) {
            return ['ok' => true, 'value' => true, 'sentAs' => $name];
        }
        if (in_array($normalised, ['0', 'false', 'no'], true)) {
            return ['ok' => true, 'value' => false, 'sentAs' => $name];
        }

        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => "{$name} must be true or false (also accepted: 1/0, yes/no) - omit it entirely for the default"
        ], 400)];
    }

    return ['ok' => true, 'value' => $default, 'sentAs' => null];
}

// Serialises the read-modify-write sequences in this file: the Notes owner marker, the attachments cell,
// and the active-estimate cap's count-then-create. A MySQL advisory lock is used rather than a transient
// or an option row because GET_LOCK is atomic across PHP workers, and MySQL frees it automatically when
// the connection ends - so a worker killed mid-request cannot strand the lock the way a transient's TTL
// would leave every later writer blocked until it expired.
//
// Take at most ONE of these per request. MySQL 5.6 and earlier release a connection's existing lock when
// GET_LOCK is called again, so nesting two of them would silently drop the outer one.
function withAdvisoryLock($key, callable $work) {
    global $wpdb, $pe_lock_timeout_seconds;

    // sha1 keeps the name inside MySQL's 64-character limit and free of anything that would need quoting
    $name = 'pe_' . sha1($key);
    $held = false;

    // suppress_errors: a host that does not offer GET_LOCK, or a proxy that refuses it, belongs in the log
    // below rather than surfacing to the caller as a raw WordPress database error
    $previous = $wpdb->suppress_errors(true);
    $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, (int) $pe_lock_timeout_seconds));
    $wpdb->suppress_errors($previous);

    if ((int) $acquired === 1) {
        $held = true;
    } else {
        // Availability over strictness, deliberately. Deployment here is a manual file upload, so a lock
        // helper that refused writes on a host without GET_LOCK would take every estimate write down with
        // no fast way back; degrading costs only the rare race the lock would have prevented. The two
        // causes are logged apart because they call for opposite responses - one is a missing host
        // capability, the other is genuine contention.
        $why = ($acquired === null)
            ? 'GET_LOCK unavailable on this host'
            : "timed out after {$pe_lock_timeout_seconds}s - another writer holds it";
        error_log("[ProjectEstimator] advisory lock not held for {$key}: {$why} - proceeding unlocked");
    }

    try {
        return $work();
    } finally {
        if ($held) {
            $previous = $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            $wpdb->suppress_errors($previous);
        }
    }
}

//-----------------------------CONNECTION TEST------------------------------------------ keep
function pingConnection(WP_REST_Request $request){
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;

    // fall back to PHP's own clock if WP isn't fully bootstrapped for some reason
    $time = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    $data = ['time' => $time];

    // Version state, reported here so the app can check it ONCE at launch and prompt the user itself rather
    // than finding out from a failed request later. /ping is never blocked or throttled, so this stays
    // reachable even for a caller that 'block' mode refuses everywhere else.
    $config = versionConfig();
    $client = clientVersionStatus($request);
    $api = apiVersionStatus($request);
    $band = worstVersionBand([$client['band'], $api['band']]);

    $data['versions'] = [
        'apiRelease' => $config['apiRelease'], // what this server actually is
        'mode' => $config['mode'],
        'band' => $band,                       // the worse of the two below
        // true only when this caller would actually be refused; advisory modes always report false
        'blocked' => $config['mode'] === 'block' && $band === 'danger',
        'client' => $client,                   // band + sent + min + recommended
        'api' => $api,
    ];

    // optional dependency probe: ?airtable-ping=true also checks Airtable reachability
    $checkAirtable = filter_var($request->get_param('airtable-ping'), FILTER_VALIDATE_BOOLEAN);
    if ($checkAirtable) {
        $airtableHelper = new AirtableHelper();
        // FALSE() returns zero records but a 200, so we confirm connectivity without pulling any data
        $probe = $airtableHelper->fetchRecords([
            'baseId' => $project_estimator_accounts_baseId,
            'tableId' => $project_estimator_accounts_tableId,
            'API_KEY' => PROJEST_API_KEY
        ], 'FALSE()');

        $data['airtable'] = $probe['success'] === true;
        if (!$probe['success']) {
            $data['airtable_error'] = $probe['error'] ?? 'unknown';
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Project Estimator API is reachable!',
        'data' => $data,
    ], 200);
}

//-----------------------------START: TIME MONITORING------------------------------------------ keep
function getActivityCodes(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_activitycodes_tableId;
	$airtableHelper = new AirtableHelper();

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $fieldList = parseFieldList($request);
    if (!$fieldList['ok']) {
        return $fieldList['response'];
    }

    $fetchActivityCodes = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_activitycodes_tableId
	], null, $fieldList['fields'], false, $rowLimit['limit']);

	//error
	if (!$fetchActivityCodes['success']) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Failed to fetch activity codes!',
			'error' => $fetchActivityCodes['error'] // show the real reason
		], 502);
	}
	else {
		$records = $fetchActivityCodes['result'];
		return withFieldsDeprecation(new WP_REST_Response([
		    'success' => true,
			'data' => $records,
		], 200), $fieldList, 6);
	}
}
//-----------------------------END: TIME MONITORING------------------------------------------ keep

//-----------------------------START: PROJECT MANAGEMENT-------------------------------------- keep
function getProjects(WP_REST_Request $request){
    global $users_and_projects_baseId,$users_and_projects_projects_tableId;
	$airtableHelper = new AirtableHelper();

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $fieldList = parseFieldList($request);
    if (!$fieldList['ok']) {
        return $fieldList['response'];
    }

    $fetchProjects = $airtableHelper->fetchRecords ([
		'baseId' => $users_and_projects_baseId,
		'tableId' => $users_and_projects_projects_tableId
	], null, $fieldList['fields'], false, $rowLimit['limit']);

	//error
	if (!$fetchProjects['success']) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Failed to fetch projects!',
			'error' => $fetchProjects['error'] // show the real reason
		], 502);
	}
	else {
		$records = $fetchProjects['result'];
		return withFieldsDeprecation(new WP_REST_Response([
		    'success' => true,
			'data' => $records,
		], 200), $fieldList, 82);
	}
}
function getUserReports(WP_REST_Request $request){
    global $users_and_projects_baseId, $users_and_projects_userreports_tableId;
    $filter = $request->get_param('filter'); // Airtable filterByFormula string, passed through as-is

    $airtableHelper = new AirtableHelper();

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $fieldList = parseFieldList($request);
    if (!$fieldList['ok']) {
        return $fieldList['response'];
    }

    $fetchUserReports = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $users_and_projects_userreports_tableId
    ], $filter, $fieldList['fields'], false, $rowLimit['limit']);

    if (!$fetchUserReports['success']) {
        $error = $fetchUserReports['error'] ?? '';
        // a bad filterByFormula (e.g. an unknown/misspelled field name) is a client error, not a server fault
        if (stripos($error, 'INVALID_FILTER_BY_FORMULA') !== false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid filter: check the field names in filterByFormula against the reports table (names are case-sensitive).',
                'error' => $error
            ], 400);
        }
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch user reports!',
            'error' => $error
        ], 502);
    } else {
        return withFieldsDeprecation(new WP_REST_Response([
            'success' => true,
            'data' => $fetchUserReports['result'],
        ], 200), $fieldList, 25);
    }
}
//-----------------------------END: PROJECT MANAGEMENT-------------------------------------- keep

//-----------------------------START: USERS-------------------------------------------------- keep
// GRID VIEW - ACTIVE STATUS TABLE, restricted to ID Number (fld1H4xvaNXDg7GlR) and Location (fld0X7E9GamwaTAaP) only
function getUserRegions(WP_REST_Request $request){
    global $users_and_projects_baseId, $active_status_table_id, $active_status_id_number_column_id, $active_status_location_column_id;
    $airtableHelper = new AirtableHelper();

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    // fields[] restricts Airtable's response to just these two columns; the response is still keyed by
    // the table's real column names ("ID No.", "Location"), confirmed against the live table
    $fetchUserRegions = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $active_status_table_id
    ], null, [$active_status_id_number_column_id, $active_status_location_column_id], false, $rowLimit['limit']);

    if (!$fetchUserRegions['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch user regions!',
            'error' => $fetchUserRegions['error']
        ], 502);
    }

    $records = array_map(function($record) {
        return [
            'id' => $record['id'],
            'fields' => [
                'ID Number' => $record['fields']['ID No.'] ?? null,
                'Location' => $record['fields']['Location'] ?? null,
            ],
        ];
    }, $fetchUserRegions['result']);

    return new WP_REST_Response([
        'success' => true,
        'data' => $records,
    ], 200);
}
//-----------------------------END: USERS---------------------------------------------------- keep

//-----------------------------START: CALENDAR------------------------------------------------ keep
// HOLIDAY TABLE, restricted to Name, Date, and Special Holiday Type, upcoming (Date >= today) only
function getHolidays(WP_REST_Request $request){
    global $users_and_projects_baseId, $holiday_table_id;
    $airtableHelper = new AirtableHelper();

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $fetchHolidays = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $holiday_table_id
    ], '{Date} >= TODAY()', ['Special Holiday Type', 'Date', 'Name'], false, $rowLimit['limit']);

    if (!$fetchHolidays['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch holidays!',
            'error' => $fetchHolidays['error']
        ], 502);
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => $fetchHolidays['result'],
    ], 200);
}
//-----------------------------END: CALENDAR-------------------------------------------------- keep

//-----------------------------START: PROJECT ESTIMATES---------------------------------------- keep
// PROJECT ESTIMATES TABLE - Assignee is the owner's Accounts record id (rec...). Airtable's Assignee
// column is still Single collaborator and can't hold it, so on create we stamp it onto Notes as an
// [owner:rec...] marker and strip it from fields; every record-scoped endpoint reads that marker back
// to enforce ownership (only the owner may list/mutate). The Assignee id must come from the same
// accounts table used for login, since ownership compares it against the caller's session record id.
// Revert to writing the Assignee field directly, and drop the marker handling, once its type changes.

// Owner marker lives in Notes as [owner:recXXXXXXXXXXXXXX]; returns the id, or null if no marker is present.
function extractEstimateOwnerId($notes) {
    if (!is_string($notes)) {
        return null;
    }
    return preg_match('/\[owner:(rec[a-zA-Z0-9]{14})\]/', $notes, $matches) === 1 ? $matches[1] : null;
}

// Prepends the owner marker to the client's Notes; keeps whatever Notes the client sent below it.
function buildNotesWithOwner($ownerId, $existingNotes) {
    $existingNotes = is_string($existingNotes) ? $existingNotes : '';
    return trim('[owner:' . $ownerId . ']' . ($existingNotes !== '' ? "\n" . $existingNotes : ''));
}

// Fetches the estimate (Notes + Attachments) and confirms $authUser owns it. Returns
// ['ok' => true, 'record' => <record>], or ['ok' => false, 'response' => WP_REST_Response] to return as-is.
// A record with no owner marker is denied, matching the deny-unknown-owner rule.
function loadOwnedEstimate(AirtableHelper $airtableHelper, $recordId, $authUser) {
    global $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id;

    // guard the id at the sink too (callers already validate): it is interpolated into filterByFormula below
    if (!isValidAirtableRecordId($recordId)) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'recordId is required and must be a valid Airtable record id'
        ], 400)];
    }

    // Name is fetched too so callers that show or send the estimate (e.g. /estimates/share's subject
    // line) do not need a second round-trip; the endpoints that only need ownership just ignore it
    $fetchRecord = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimates_table_id
    // Scope is fetched here too, for the same reason Name already is: the booking check in uploadEstimateFile
    // needs the record's own scope pool (R7) and this way costs it nothing extra.
    ], "RECORD_ID()='{$recordId}'", ['Name', 'Notes', 'Scope', $project_estimates_attachments_column_id]);

    if (!$fetchRecord['success']) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to verify project estimate record!',
            'error' => $fetchRecord['error']
        ], 502)];
    }
    if (empty($fetchRecord['result'])) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'Project Estimate record not found'
        ], 404)];
    }

    $record = $fetchRecord['result'][0];
    $ownerId = extractEstimateOwnerId($record['fields']['Notes'] ?? '');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;

    // no owner marker, or a marker that isn't this caller's account = forbidden
    if (!isValidAirtableRecordId($callerId) || $ownerId !== $callerId) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'You do not have permission to access this project estimate'
        ], 403)];
    }

    return ['ok' => true, 'record' => $record];
}

// The active-estimate cap: a file default with a stored override on top, the same shape as the rate-limit
// buckets so the number can be moved without a redeploy. 0 means no cap, matching those buckets' meaning
// of max: 0, rather than inventing a separate "enabled" flag that could disagree with the number.
function estimateLimitsConfig() {
    global $pe_max_active_estimates, $pe_estimate_limits_option;

    $stored = get_option($pe_estimate_limits_option, []);
    $stored = is_array($stored) ? $stored : [];

    $fileDefault = (int) $pe_max_active_estimates;
    $override = $stored['maxActiveEstimates'] ?? null;
    // >= 0 rather than > 0: a stored 0 is a deliberate "no cap" override and must win over a non-zero
    // file default, not be discarded as falsy
    $effective = ($override !== null && (int) $override >= 0) ? (int) $override : $fileDefault;

    return [
        'maxActiveEstimates' => $effective,
        'fileDefault' => $fileDefault,
        'overridden' => $override !== null,
        'enabled' => $effective > 0,
    ];
}

// Counts the estimates this account owns whose Status is not Done. Ownership lives in the Notes marker
// rather than a real column, so it has to be a formula match. $stopAt caps the rows Airtable returns:
// the caller only needs to know whether the cap is reached, so fetching more than that many rows would
// be wasted work no matter how many estimates the account owns.
function countActiveEstimatesFor(AirtableHelper $airtableHelper, $ownerId, $stopAt = null) {
    global $users_and_projects_baseId, $project_estimates_table_id;

    // guard at the sink as well as the caller: this is interpolated straight into filterByFormula
    if (!isValidAirtableRecordId($ownerId)) {
        return ['ok' => false, 'error' => 'invalid owner record id'];
    }

    // A record with no Status at all counts as active. Airtable's != returns true for a blank cell, which
    // is the behaviour we want: an estimate nobody has triaged yet is still work in hand.
    $filter = "AND(FIND(\"[owner:{$ownerId}]\", {Notes}) > 0, {Status} != \"Done\")";

    // only the count is used, so ask for one small column rather than every field
    $fetch = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimates_table_id
    ], $filter, ['Name'], false, $stopAt);

    if (!$fetch['success']) {
        return ['ok' => false, 'error' => $fetch['error'] ?? 'Unknown error'];
    }

    return ['ok' => true, 'count' => count($fetch['result'])];
}

// The modified stamp: ISO 8601, UTC. gmdate rather than date so the value never depends on the server's
// timezone setting, and Z notation so nothing downstream has to guess - the column is plain text, so this
// string is the only type information it carries. Same shape as Airtable's own createdTime.
function estimateModifiedStamp() {
    return gmdate('Y-m-d\TH:i:s\Z');
}

// Adds the stamp to a fields array on its way to Airtable. Centralised because four write paths need it,
// and one of them forgetting would be invisible - nothing would break, the timestamp would just quietly be
// wrong. Returns $fields untouched while the column is disabled, so one empty global turns it all off.
function withModifiedStamp(array $fields) {
    global $project_estimates_modified_column;

    if ($project_estimates_modified_column !== '') {
        $fields[$project_estimates_modified_column] = estimateModifiedStamp();
    }

    return $fields;
}

// Airtable formula string literal: wrap in double quotes, escaping backslash then quote. Per Airtable's
// own docs ("use a backslash as an escape character" for a literal quote inside formula text) - NOT
// doubled quotes, which is a different convention this could have silently gotten wrong. Backslash is
// escaped first so an already-escaped quote is never re-escaped.
function airtableFormulaStringLiteral($text) {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $text) . '"';
}

// Deliberately just the date portion, by string slicing rather than a timezone-aware parse: the proposal's
// open question 3 (day normalization / canonical timezone) is left open on purpose, and a strtotime()
// round trip would silently pick an answer - usually the server's own timezone - to a question nobody has
// confirmed. Whatever calendar date the client's own string begins with is used exactly as sent.
function normalizeBookingDate($value) {
    if (!is_string($value) || strlen($value) < 10) {
        return null;
    }
    $datePart = substr($value, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart) === 1 ? $datePart : null;
}

// R5's "is this still running" check needs a notion of "today", which date-only comparison otherwise has
// no use for. Asia/Manila per the proposal's stated recommendation (matches the Cebu/Laguna regions in the
// app) - a recommendation, not a decided answer; the same open question as above.
function bookingTodayDate() {
    // Leading \ is required: this file is namespaced, and PHP does not fall back to the global namespace
    // for classes the way it does for functions - DateTime here silently meant
    // portalikaikabim\...\DateTime, a class that does not exist, fatal on every call (2026-08-11).
    return (new \DateTime('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

// R1: inclusive range overlap on 'Y-m-d' strings, which sort lexicographically exactly as they sort
// chronologically. R6: any missing bound means no comparison is possible, so this alone never overlaps -
// R5 below is the separate, looser test for that case.
function estimatesOverlap($aStart, $aEnd, $bStart, $bEnd) {
    if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
        return false;
    }
    return $aStart <= $bEnd && $bStart <= $aEnd;
}

// Composes R1/R5/R6 into the one answer a candidate estimate needs to give: does it conflict with the
// booking being saved. This is a reimplementation of prose describing client code this repo cannot read
// directly (app_state_estimates.dart currentBookings/blockingBookings) rather than a line-for-line port -
// documented as best-effort, unlike R1/R6 above which are unambiguous from the source spec.
function bookingConflicts($newStart, $newEnd, $candidateStart, $candidateEnd, $today) {
    if ($newStart !== null && $newEnd !== null && $candidateStart !== null && $candidateEnd !== null) {
        return estimatesOverlap($newStart, $newEnd, $candidateStart, $candidateEnd);
    }
    if ($candidateStart === null && $candidateEnd === null) {
        return false; // no window at all to test "still running" against either
    }
    return $candidateEnd === null || $candidateEnd >= $today;
}

function bookingLimitsConfig() {
    global $pe_max_concurrent_bookings, $pe_booking_limits_option, $pe_booking_enforce_mode;

    $stored = get_option($pe_booking_limits_option, []);
    $stored = is_array($stored) ? $stored : [];

    $fileDefault = (int) $pe_max_concurrent_bookings;
    $override = $stored['maxConcurrentBookings'] ?? null;
    $effective = ($override !== null && (int) $override >= 0) ? (int) $override : $fileDefault;

    $allowedModes = ['off', 'warn', 'block'];
    $modeOverride = $stored['mode'] ?? null;
    $effectiveMode = (is_string($modeOverride) && in_array($modeOverride, $allowedModes, true))
        ? $modeOverride
        : $pe_booking_enforce_mode;

    return [
        'maxConcurrentBookings' => $effective,
        'fileDefault' => $fileDefault,
        'overridden' => $override !== null,
        'enabled' => $effective > 0,
        'mode' => $effectiveMode,
        'modeFileDefault' => $pe_booking_enforce_mode,
        'modeOverridden' => $modeOverride !== null,
    ];
}

// Candidate narrower, not the correctness check - hands back "estimates that might conflict" and leaves
// R1/R5/R6's actual date math to blockedMembersFor() below, the same division of labour
// countActiveEstimatesFor() already uses for the unrelated owner-cap. Reuses the FIND()-in-filterByFormula
// technique ADR 0001 proved out for the [owner:rec...] Notes marker. R4 (Done releases immediately) is a
// filter term here, the cheapest place to spend it; R7 (scope pooling) is the {Scope} = term, so a Local
// check never even fetches Global rows; R8 (self-exclusion) is applied after the fetch, in PHP.
function memberBookingCandidates(AirtableHelper $airtableHelper, $memberName, $scope, $excludeRecordId) {
    global $users_and_projects_baseId, $project_estimates_table_id,
        $project_estimates_start_column, $project_estimates_end_column, $project_estimates_membertrades_column;

    $needle = airtableFormulaStringLiteral('"' . $memberName . '"'); // exactly how it appears as a JSON key
    $filter = 'AND({Scope} = ' . airtableFormulaStringLiteral($scope)
        . ', NOT({Status} = "Done")'
        . ', FIND(' . $needle . ', {' . $project_estimates_membertrades_column . '}) > 0)';

    $result = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimates_table_id
    ], $filter, ['Name', $project_estimates_start_column, $project_estimates_end_column]);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?? 'unknown error'];
    }

    $candidates = array_values(array_filter($result['result'], function ($record) use ($excludeRecordId) {
        return $record['id'] !== $excludeRecordId; // R8
    }));

    return ['success' => true, 'records' => $candidates];
}

// The one function uploadEstimateFile() actually calls. Runs the full R1/R4/R5/R6/R7/R8 check for every
// member named in the booking being saved, and returns which of them are already at or over the limit -
// matching the shape the source spec says the client already renders (a list of blocked names, not one).
function blockedMembersFor(AirtableHelper $airtableHelper, $newStart, $newEnd, $scope, $excludeRecordId, array $memberNames) {
    $limits = bookingLimitsConfig();
    if ($limits['mode'] === 'off' || !$limits['enabled']) {
        return ['blocked' => [], 'limit' => $limits['maxConcurrentBookings'], 'mode' => $limits['mode']];
    }

    global $project_estimates_start_column, $project_estimates_end_column;
    $today = bookingTodayDate();
    $blocked = [];

    foreach (array_unique($memberNames) as $memberName) {
        if (!is_string($memberName) || $memberName === '') {
            continue;
        }
        $candidates = memberBookingCandidates($airtableHelper, $memberName, $scope, $excludeRecordId);
        if (!$candidates['success']) {
            // Fails open, deliberately - the same availability-over-strictness choice withAdvisoryLock()
            // already makes: an Airtable read failure here must not turn into every upload refusing.
            error_log("[ProjectEstimator] booking check failed for {$memberName}: " . ($candidates['error'] ?? 'unknown error'));
            continue;
        }

        $conflictCount = 0;
        foreach ($candidates['records'] as $record) {
            $cStart = normalizeBookingDate($record['fields'][$project_estimates_start_column] ?? null);
            $cEnd = normalizeBookingDate($record['fields'][$project_estimates_end_column] ?? null);
            if (bookingConflicts($newStart, $newEnd, $cStart, $cEnd, $today)) {
                $conflictCount++;
            }
        }

        if ($conflictCount >= $limits['maxConcurrentBookings']) { // R3: >=, not >
            $blocked[] = $memberName;
        }
    }

    return ['blocked' => $blocked, 'limit' => $limits['maxConcurrentBookings'], 'mode' => $limits['mode']];
}

// Name -> email for everyone in the Active Status grid, keyed by memberDirectoryKey().
//
// Requests ONLY Name and Email. See the note beside $active_status_name_column for why that matters more
// here than it usually would.
//
// Fails EMPTY rather than fatally, the same availability-over-strictness choice blockedMembersFor() makes:
// a staff-table read that failed must not turn into an upload that refuses. The cost is a blank cell until
// the next save, which is what a projection is allowed to cost.
function memberEmailDirectory(AirtableHelper $airtableHelper) {
    global $users_and_projects_baseId, $active_status_table_id,
        $active_status_name_column, $active_status_email_column,
        $pe_member_email_transient, $pe_member_email_ttl_seconds;

    $cached = get_transient($pe_member_email_transient);
    if (is_array($cached)) {
        return $cached;
    }

    $result = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $active_status_table_id
    ], null, [$active_status_name_column, $active_status_email_column]);

    if (!$result['success']) {
        error_log('[ProjectEstimator] member email directory read failed: ' . ($result['error'] ?? 'unknown error'));
        return [];
    }

    $directory = [];
    foreach ($result['result'] as $record) {
        $name = trim((string) ($record['fields'][$active_status_name_column] ?? ''));
        $email = trim((string) ($record['fields'][$active_status_email_column] ?? ''));
        if ($name === '' || $email === '') {
            continue;
        }
        $directory[memberDirectoryKey($name)] = $email;
    }

    // An empty directory is indistinguishable from a failed or half-read staff table, and caching that
    // would keep every estimate blank for the whole TTL rather than for one upload.
    if (!empty($directory)) {
        set_transient($pe_member_email_transient, $directory, $pe_member_email_ttl_seconds);
    }

    return $directory;
}

// Case and surrounding whitespace only. Member Trades keys are typed on the client and the staff table is
// maintained by hand, so those two drift in exactly that way; anything more aggressive would start merging
// people who genuinely have similar names.
function memberDirectoryKey($name) {
    return strtolower(trim((string) $name));
}

// Name -> email for exactly the members named in Member Trades, with the SAME keys, so the two columns
// line up row for row.
//
// A member with no match keeps their key with an empty value rather than vanishing. "We could not resolve
// this person" and "this person is not on the project" are different facts, and a silently shorter list
// reads as the second one.
function memberEmailsFor(array $memberNames, array $directory) {
    $emails = [];
    foreach ($memberNames as $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $emails[$name] = $directory[memberDirectoryKey($name)] ?? '';
    }
    return $emails;
}

// When the project runs and who is on it, in one self-contained object.
//
// Unresolved members are dropped HERE and not from Member Emails: attendees is a list to send to, and an
// empty address is nothing to send to - while Member Emails still shows the gap, which is where you would
// go to find out who is missing.
function bookingSchedulePayload($projectStart, $projectEnd, array $memberEmails) {
    $attendees = [];
    foreach ($memberEmails as $email) {
        $address = trim((string) $email);
        if ($address !== '' && !in_array($address, $attendees, true)) {
            $attendees[] = $address;
        }
    }

    // Nothing to say at all, so the column is cleared rather than left holding an object of nulls that
    // reads like a booking with no dates.
    if ($projectStart === null && $projectEnd === null && empty($attendees)) {
        return null;
    }

    return [
        'start' => $projectStart,
        'end' => $projectEnd,
        'attendees' => $attendees,
    ];
}

// Projects the three booking fields onto $fields, honoring the same "empty global disables it" idiom as
// withModifiedStamp(). Included even when null/empty, deliberately: a client that clears its own dates or
// members should see that clearing reach the projected columns too, rather than leaving a stale value that
// no longer matches the attachment it is meant to mirror (see the proposal S4.1 on that drift).
function withBookingProjection(AirtableHelper $airtableHelper, array $fields, $projectStart, $projectEnd, $memberTradesRaw) {
    global $project_estimates_start_column, $project_estimates_end_column, $project_estimates_membertrades_column,
        $project_estimates_memberemails_column, $project_estimates_schedule_column;

    if ($project_estimates_start_column !== '') {
        $fields[$project_estimates_start_column] = $projectStart;
    }
    if ($project_estimates_end_column !== '') {
        $fields[$project_estimates_end_column] = $projectEnd;
    }
    if ($project_estimates_membertrades_column !== '') {
        $fields[$project_estimates_membertrades_column] = (is_array($memberTradesRaw) && !empty($memberTradesRaw))
            ? wp_json_encode($memberTradesRaw)
            : null;
    }

    // Read from $memberTradesRaw rather than from the column above, so disabling Member Trades stops it
    // being STORED without also stopping the emails resolving - those are two different decisions.
    $memberNames = (is_array($memberTradesRaw) && !empty($memberTradesRaw)) ? array_keys($memberTradesRaw) : [];

    // The directory read is skipped entirely when neither column wants it, which is what keeps this
    // function free of an Airtable round-trip while the two columns are still switched off.
    $wantsEmails = $project_estimates_memberemails_column !== '' || $project_estimates_schedule_column !== '';
    $memberEmails = ($wantsEmails && !empty($memberNames))
        ? memberEmailsFor($memberNames, memberEmailDirectory($airtableHelper))
        : [];

    if ($project_estimates_memberemails_column !== '') {
        $fields[$project_estimates_memberemails_column] = !empty($memberEmails)
            ? wp_json_encode($memberEmails)
            : null;
    }

    if ($project_estimates_schedule_column !== '') {
        $schedule = bookingSchedulePayload($projectStart, $projectEnd, $memberEmails);
        $fields[$project_estimates_schedule_column] = ($schedule !== null) ? wp_json_encode($schedule) : null;
    }

    return $fields;
}

// The READ counterpart of withBookingProjection. Those three columns hold JSON *text* -
// wp_json_encode wrote them - so a client handed the raw value would have to parse its own
// payload a second time. Decoded once here instead, which is also what lets the response say
// null for "nothing recorded" rather than the string "null".
//
// Anything unreadable degrades to null rather than throwing: the column is written by this API,
// but it is an ordinary text field a human can edit in Airtable, so malformed content is a real
// state - and one bad row must not take down the whole listing.
//
// One helper rather than three copies: the same decode rule applies to all three columns, and
// the empty-column guard is the same one $wanted needs (an unconfigured column is never
// requested, so its key is simply absent from the record).
// Untyped $record to match the rest of this file, which reads records with ?? rather than
// asserting their shape - an array hint here would throw on a row the surrounding code would
// simply have returned null for.
function decodeEstimateJsonColumn($record, $column) {
    if ($column === '') { return null; }
    $raw = is_array($record) ? ($record['fields'][$column] ?? null) : null;
    if (!is_string($raw) || trim($raw) === '') { return null; }
    $decoded = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
}

// Proposal S6.2: the lock has to be per MEMBER being booked, not per record - two different estimates
// booking the same person is the actual collision, and the record-scoped lock already in
// updateProjectEstimate/deleteEstimateFile does nothing for it. Acquires one advisory lock per name, in a
// fixed (sorted) order, so two uploads naming overlapping members can never wait on each other in opposite
// orders - the standard fix for the deadlock a per-member lock would otherwise risk. Recurses one member
// at a time rather than trying to acquire several at once, since GET_LOCK only ever takes one name. An
// empty list takes no lock at all and just runs $work() directly.
function withMemberLocks(array $memberNames, callable $work) {
    $sorted = array_values(array_unique($memberNames));
    sort($sorted);
    return withMemberLocksSorted($sorted, $work);
}

function withMemberLocksSorted(array $sortedNames, callable $work) {
    if (empty($sortedNames)) {
        return $work();
    }
    $name = array_shift($sortedNames);
    return withAdvisoryLock('booking-member:' . $name, function () use ($sortedNames, $work) {
        return withMemberLocksSorted($sortedNames, $work);
    });
}

function createProjectEstimate(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id;
    $payload = $request->get_param('payload'); // { fields: { Name, Notes, Status, Scope } }
    $airtableHelper = new AirtableHelper();

    // The owner is always the authenticated caller - see the Assignee block below for why this can no
    // longer come from the client.
    $authUser = $request->get_param('authUser');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;
    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    if (!is_array($payload) || !isset($payload['fields']) || !is_array($payload['fields'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields is required'
        ], 400);
    }

    $name = $payload['fields']['Name'] ?? null;
    if (!is_string($name) || $name === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Name is required'
        ], 400);
    }

    $status = $payload['fields']['Status'] ?? null;
    $allowedStatuses = ['Todo', 'In progress', 'Done'];
    if ($status !== null && !in_array($status, $allowedStatuses, true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Status must be one of: ' . implode(', ', $allowedStatuses)
        ], 400);
    }

    // Scope (fldV5VuRIzt1arI8P) controls visibility in /estimates/list: Global is returned to every caller,
    // Local only to its owner. Left unset rather than defaulted here - Airtable then reads it as blank, which
    // listMyEstimates treats the same as Local, so a client that predates this field keeps its estimates
    // private exactly as before.
    $scope = $payload['fields']['Scope'] ?? null;
    $allowedScopes = ['Local', 'Global'];
    if ($scope !== null && !in_array($scope, $allowedScopes, true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Scope must be one of: ' . implode(', ', $allowedScopes)
        ], 400);
    }

    // Owner is $callerId, unconditionally - a client-sent Assignee is discarded rather than trusted. It used
    // to win here, which let a stale or wrong Assignee create an estimate nobody's own session could ever
    // see again in /estimates/list: the FIND(owner) filter there matches the session's OWN recordId, and a
    // Local estimate has no Global fallback to be found under (fixed 2026-08-11).
    $assigneeId = $callerId;
    unset($payload['fields']['Assignee']); // never a writable Airtable column; ownership lives in the Notes marker

    $payload['fields']['Notes'] = buildNotesWithOwner($assigneeId, $payload['fields']['Notes'] ?? '');

    // Locked on the OWNER rather than a record id, because there is no record yet. The cap below is a
    // count-then-create: without this, two simultaneous creates could both read count = max - 1, both pass
    // the check, and leave the owner one over the limit. Scoped per owner so two different people creating
    // at the same time never wait on each other.
    return withAdvisoryLock('estimate-owner:' . $assigneeId, function () use ($airtableHelper, $payload, $assigneeId, $users_and_projects_baseId, $project_estimates_table_id) {
        $limits = estimateLimitsConfig();
        if ($limits['enabled']) {
            $active = countActiveEstimatesFor($airtableHelper, $assigneeId, $limits['maxActiveEstimates']);
            if (!$active['ok']) {
                // fail the create rather than allow it past an unverifiable cap - a limit that silently
                // stops applying whenever Airtable hiccups is not a limit
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Could not verify the active estimate limit, so nothing was created. Try again.',
                    'error' => $active['error']
                ], 502);
            }
            if ($active['count'] >= $limits['maxActiveEstimates']) {
                // 409 rather than 403: the request is well formed and the caller is allowed to create
                // estimates, it just conflicts with how much work this account already has open
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "This account already has {$active['count']} active estimate(s), which is the limit of {$limits['maxActiveEstimates']}. Mark one Done to free a slot.",
                    'error' => 'estimate_limit_reached',
                    'data' => [
                        'activeEstimates' => $active['count'],
                        'maxActiveEstimates' => $limits['maxActiveEstimates']
                    ]
                ], 409);
            }
        }

        // stamped in here rather than before the lock, so the value records when the write actually
        // happened rather than when the request started queueing behind another writer
        $payload['fields'] = withModifiedStamp($payload['fields']);

        // uploadRecords expects a list of records
        $uploadResult = $airtableHelper->uploadRecords([$payload], [
            'baseId' => $users_and_projects_baseId,
            'tableId' => $project_estimates_table_id
        ]);

        if (!$uploadResult['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to create project estimate!',
                'error' => $uploadResult['error']
            ], 502);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $uploadResult['result'],
            'message' => 'Project estimate created!'
        ], 200);
    });
}

// PATCH /estimates/update - partial update to Name, Notes, Status and/or Scope on an estimate the CALLER
// owns. At least one field is required; any field left out of payload.fields is untouched (Airtable's PATCH
// semantics - this never falls back to PUT, which would blank out whatever was not sent).
//
// Assignee and Attachments are refused outright rather than silently dropped: Assignee is fixed at creation
// (ownership does not move through this endpoint), and Attachments already has its own /files/upload and
// /files/delete flow with its own validation - accepting it here would be a second, less-checked path to the
// same column. Rejecting loudly means a client that tries either finds out immediately.
//
// Notes is never written back as the client sent it. /estimates/list strips the [owner:rec...] marker before
// handing Notes to the client, so a normal edit-and-resave round trip arrives here with no marker in it -
// buildNotesWithOwner() reattaches it, same as create does. Skipping that step would silently orphan the
// record: the very next /estimates/list call would stop finding it, for anyone, including its own owner.
function updateProjectEstimate(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id;
    $authUser = $request->get_param('authUser'); // set by validateAuthToken via this route's permission_callback
    $payload = $request->get_param('payload'); // { recordId, fields: { Name?, Notes?, Status?, Scope? } }
    $airtableHelper = new AirtableHelper();

    $recordId = is_array($payload) ? ($payload['recordId'] ?? null) : null;
    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.recordId is required and must be a valid Airtable record id'
        ], 400);
    }

    if (!isset($payload['fields']) || !is_array($payload['fields']) || $payload['fields'] === []) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields is required and must contain at least one field to change'
        ], 400);
    }
    $fields = $payload['fields'];

    if (array_key_exists('Assignee', $fields)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Assignee cannot be changed here - ownership is fixed at creation'
        ], 400);
    }
    if (array_key_exists('Attachments', $fields)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Attachments cannot be changed here - use /files/upload or /files/delete'
        ], 400);
    }

    $allowedFields = ['Name', 'Notes', 'Status', 'Scope'];
    $unknownFields = array_diff(array_keys($fields), $allowedFields);
    if ($unknownFields !== []) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields has unsupported key(s): ' . implode(', ', $unknownFields)
                . '. Allowed: ' . implode(', ', $allowedFields)
        ], 400);
    }

    if (array_key_exists('Name', $fields) && (!is_string($fields['Name']) || $fields['Name'] === '')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Name must be a non-empty string'
        ], 400);
    }

    // same allowed lists and message shape as createProjectEstimate, so a client sees one rule, not two
    $allowedStatuses = ['Todo', 'In progress', 'Done'];
    if (array_key_exists('Status', $fields) && !in_array($fields['Status'], $allowedStatuses, true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Status must be one of: ' . implode(', ', $allowedStatuses)
        ], 400);
    }

    $allowedScopes = ['Local', 'Global'];
    if (array_key_exists('Scope', $fields) && !in_array($fields['Scope'], $allowedScopes, true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Scope must be one of: ' . implode(', ', $allowedScopes)
        ], 400);
    }

    if (array_key_exists('Notes', $fields) && !is_string($fields['Notes'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Notes must be a string'
        ], 400);
    }

    // Locked on the record for the whole ownership-read-then-write sequence. Notes is read-modify-write:
    // the owner marker is stripped from the incoming text and reattached from the record we just read, so
    // two concurrent updates interleaving between the read and the patch would let the later one overwrite
    // the earlier one's Notes - and a marker rebuilt from a stale read is how a record gets orphaned out of
    // /estimates/list entirely. Validation stays outside the lock: a malformed request should 400 without
    // ever queueing behind another writer.
    return withAdvisoryLock('estimate:' . $recordId, function () use ($airtableHelper, $recordId, $authUser, $fields, $users_and_projects_baseId, $project_estimates_table_id) {
        // ownership is checked AFTER shape validation (a malformed request should 400 before it ever touches
        // Airtable) but BEFORE anything is written
        $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
        if (!$owned['ok']) {
            return $owned['response'];
        }
        $callerId = $authUser['recordId']; // loadOwnedEstimate has already confirmed this is the record's owner

        if (array_key_exists('Notes', $fields)) {
            // strip any owner-marker-shaped prefix defensively - the same regex /estimates/list applies before
            // this text ever reaches a client - then reattach the real marker. Never trust client-supplied Notes
            // to already be marker-free just because it normally is.
            $clientNotes = preg_replace('/^\[owner:rec[a-zA-Z0-9]{14}\]\n?/', '', $fields['Notes']);
            $fields['Notes'] = buildNotesWithOwner($callerId, $clientNotes);
        }

        $fields = withModifiedStamp($fields);

        $patchResult = $airtableHelper->patchRecords([[
            'id' => $recordId,
            'fields' => $fields
        ]], [
            'baseId' => $users_and_projects_baseId,
            'tableId' => $project_estimates_table_id
        ]);

        if (!$patchResult['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update project estimate!',
                'error' => $patchResult['error']
            ], 502);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $patchResult['result'],
            'message' => 'Project estimate updated!'
        ], 200);
    });
}

function deleteProjectEstimate(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id;
    $authUser = $request->get_param('authUser'); // set by validateAuthToken via this route's permission_callback
    $payload = $request->get_param('payload'); // record id string, or a list of id strings
    $airtableHelper = new AirtableHelper();

    // normalize a single id to a list; deleteRecords expects a list of id strings
    if (is_string($payload) && $payload !== '') {
        $payload = [$payload];
    }
    if (!is_array($payload) || $payload === []) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload must be a record id or a list of record ids'
        ], 400);
    }
    // every id must be well-formed: it guards the ownership filter against injection and rejects junk early
    foreach ($payload as $id) {
        if (!isValidAirtableRecordId($id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'payload must contain only valid Airtable record ids'
            ], 400);
        }
    }

    // the caller must own every record before any is deleted (all-or-nothing)
    foreach ($payload as $id) {
        $owned = loadOwnedEstimate($airtableHelper, $id, $authUser);
        if (!$owned['ok']) {
            return $owned['response'];
        }
    }

    $deleteResult = $airtableHelper->deleteRecords($payload, [
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimates_table_id
    ]);

    if (!$deleteResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to delete project estimate!',
            'error' => $deleteResult['error']
        ], 502);
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => $deleteResult['result'],
        'message' => 'Project estimate successfully deleted!'
    ], 200);
}

// GET /estimates/list - every estimate the caller owns, PLUS every OTHER account's estimate whose Scope is
// Global. Local (or unset - i.e. a record predating the Scope field) stays owner-only. This is visibility
// only: delete/share/file-attach all still call loadOwnedEstimate() and refuse anyone but the owner, so a
// Global scope lets other accounts see an estimate, never edit, delete or share it.
// Uses FIND() over Notes since RECORD_ID() only ever matches one known id; this instead searches for the
// caller's own marker across the whole table. The owner half is always the caller's OWN session id, never an
// id from the client - accepting an arbitrary owner id here would let any account list any other account's
// LOCAL estimates too, defeating the ownership model for the one scope meant to stay private.
// Embeds each estimate's files too, so one call covers both estimates and files.
function listMyEstimates(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id,
           $project_estimates_modified_column, $project_estimates_membertrades_column,
           $project_estimates_memberemails_column, $project_estimates_schedule_column;
    $authUser = $request->get_param('authUser');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;

    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    // Defaults to true, which is the behaviour that shipped with Scope - so a client that sends nothing sees
    // exactly what it saw before this flag existed. Sending false narrows the result to estimates the caller
    // OWNS, which is the useful shape for a screen whose every row is meant to be editable: a Global estimate
    // belonging to someone else comes back with isOwner false, and acting on it would earn a 403. Filtering
    // server-side beats fetching then discarding, because the rows never cross the wire.
    // Both spellings are accepted: includeGlobal matches every other param in this API, include-global is
    // the more usual query-string style, and refusing one of them would only ever be a papercut.
    $includeGlobal = parseBoolParam($request, ['includeGlobal', 'include-global'], true);
    if (!$includeGlobal['ok']) {
        return $includeGlobal['response'];
    }

    $airtableHelper = new AirtableHelper();
    // $callerId is already shape-validated above (rec + 14 alnum), so it's safe to interpolate directly.
    // The limit caps the already-filtered result (Airtable applies filterByFormula first): it can return
    // fewer rows than the formula matched, but never a row the formula did not match.
    $ownedByCaller = "FIND(\"[owner:{$callerId}]\", {Notes}) > 0";
    $filter = $includeGlobal['value']
        ? "OR({$ownedByCaller}, {Scope} = \"Global\")"
        : $ownedByCaller;

    // the last-modified column is requested only when configured: Airtable 422s on an unknown field name,
    // and that would take this whole endpoint down rather than just omitting one value
    $wanted = ['Name', 'Status', 'Scope', 'Notes', $project_estimates_attachments_column_id];
    if ($project_estimates_modified_column !== '') {
        $wanted[] = $project_estimates_modified_column;
    }
    // The booking trio, each on the same terms as the column above and for the same reason: an
    // unknown field name makes Airtable 422 the whole request, so a column that is not configured
    // is not asked for. Requesting them is not optional decoration - the fetch is a PROJECTION,
    // so a field absent from this list never reaches the response no matter what the map below does.
    foreach ([$project_estimates_membertrades_column, $project_estimates_memberemails_column,
            $project_estimates_schedule_column] as $bookingColumn) {
        if ($bookingColumn !== '') { $wanted[] = $bookingColumn; }
    }

    $fetchRecords = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimates_table_id
    ], $filter, $wanted, false, $rowLimit['limit']);

    if (!$fetchRecords['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch project estimates!',
            'error' => $fetchRecords['error']
        ], 502);
    }

    return new WP_REST_Response([
        'success' => true,
        // echoed back so a client can tell which set it is looking at without having to remember whether it
        // sent the flag - the default is the case where it genuinely cannot know
        'includeGlobal' => $includeGlobal['value'],
        'data' => array_map(function($record) use ($callerId, $project_estimates_modified_column,
                $project_estimates_membertrades_column, $project_estimates_memberemails_column,
                $project_estimates_schedule_column) {
            $notes = $record['fields']['Notes'] ?? '';
            $attachments = $record['fields']['Attachments'] ?? [];
            return [
                'id' => $record['id'],
                'name' => $record['fields']['Name'] ?? null,
                'status' => $record['fields']['Status'] ?? null,
                'scope' => $record['fields']['Scope'] ?? 'Local', // unset reads as Local, matching the filter above
                // Falls back to createdTime in both the cases where the column gives nothing: it is not
                // configured yet, or Airtable has left it empty because the record has never been edited.
                // Either way "never modified" means creation is still the last thing that happened to it,
                // and a client sorting by recency needs a real timestamp on every row rather than a null.
                // createdTime comes back on every record for free, without being requested.
                'dateModified' => ($project_estimates_modified_column !== ''
                        ? ($record['fields'][$project_estimates_modified_column] ?? null)
                        : null)
                    ?? ($record['createdTime'] ?? null),
                'createdTime' => $record['createdTime'] ?? null,
                // lets the client hide edit/delete/share controls on a Global estimate it does not own -
                // Scope only ever widens WHO SEES a row, never who may act on it
                'isOwner' => extractEstimateOwnerId($notes) === $callerId,
                // marker stripped - it's an internal workaround, not something the client needs to see
                'notes' => preg_replace('/^\[owner:rec[a-zA-Z0-9]{14}\]\n?/', '', $notes),
                'files' => array_map(function($file) {
                    return [
                        'id' => $file['id'],
                        'filename' => $file['filename'],
                        'url' => $file['url'],
                        'size' => $file['size'] ?? null,
                        'type' => $file['type'] ?? null,
                    ];
                }, $attachments),
                // Added, never reshaped: every key above is exactly as it was, so a client that
                // does not know about these three is unaffected. All three are null until an
                // estimate has been through uploadEstimateFile, which is currently the only thing
                // that writes them - a create or an update does not, so an estimate edited but
                // never re-uploaded reports the booking it had at its last upload.
                'memberTrades' => decodeEstimateJsonColumn($record, $project_estimates_membertrades_column),
                'memberEmails' => decodeEstimateJsonColumn($record, $project_estimates_memberemails_column),
                'bookingSchedule' => decodeEstimateJsonColumn($record, $project_estimates_schedule_column),
            ];
        }, $fetchRecords['result']),
    ], 200);
}

// POST /estimates/share - emails one of the CALLER'S OWN estimates to addresses the client supplies.
//
// Division of labour: Airtable stores the estimate as serialized JSON, the app renders that into a PDF
// report on the device, and this endpoint takes the finished PDF plus the recipient list and sends it.
// Nothing renders a PDF server-side.
//
// The client therefore controls three things - who receives it, an optional note, and the report file -
// and nothing else. From/Reply-To, the subject and the HTML body are all built server-side, so a stolen
// token cannot be used to send arbitrary mail (or a convincing phishing page) that appears to come from
// this domain. The attachment is the one caller-supplied payload that goes out, so it is checked to
// really be a PDF rather than taken on trust, size-capped, and its filename stripped of header-breaking
// characters. Abuse is further bounded by the ownership gate, a per-account send throttle, and a
// recipient cap per request.

// thin wrapper over the shared rate limiter, whose functions are named around failures - a SUCCESSFUL
// send still has to be counted here, since this limit is about outbound volume rather than errors
// No window passed through: the 'share' bucket resolves its own, so an operator override applies here too.
function registerEmailSend($callerId) {
    return registerRateLimitHit('share', $callerId);
}

// Normalizes payload.to (a single address or an array) into a deduped list of valid addresses.
// Returns ['ok' => true, 'to' => [...]], or ['ok' => false, 'response' => WP_REST_Response].
function parseShareRecipients($to, $maxRecipients) {
    $candidates = is_array($to) ? $to : [$to];

    if (count($candidates) > $maxRecipients) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => "payload.to accepts at most {$maxRecipients} recipients per request"
        ], 400)];
    }

    $valid = [];
    $invalid = [];
    foreach ($candidates as $address) {
        if (!is_string($address)) {
            $invalid[] = 'non-string value';
            continue;
        }
        $address = trim($address);
        if ($address === '') {
            continue;
        }
        // FILTER_VALIDATE_EMAIL also rejects CR/LF, so anything that passes here is safe in a header
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $invalid[] = $address;
            continue;
        }
        $valid[strtolower($address)] = $address; // dedupe case-insensitively, send to the original form
    }

    // one bad address rejects the whole request rather than sending to the rest: a partial send can't be
    // taken back, whereas a rejected request just gets fixed and retried
    if (!empty($invalid)) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'One or more recipients are not valid email addresses - nothing was sent!',
            'invalid' => $invalid
        ], 400)];
    }
    if (empty($valid)) {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'payload.to must contain at least one email address'
        ], 400)];
    }

    return ['ok' => true, 'to' => array_values($valid)];
}

function shareProjectEstimate(WP_REST_Request $request){
    // the send limit's numbers are not pulled in here - rateLimitConfig('share') resolves them, so an
    // operator override is reflected in both the check and the message the caller sees
    global $pe_email_from, $pe_email_reply_to, $pe_share_max_recipients,
           $pe_share_max_note_length, $pe_share_max_attachment_bytes, $pe_share_max_pdf_bytes;
    $authUser = $request->get_param('authUser');
    // { recordId, to, note, file: { filename, base64 } = the client-rendered PDF report, includeFiles }
    $payload = $request->get_param('payload');

    if (!is_array($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid payload'
        ], 400);
    }

    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;
    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    // throttled per ACCOUNT rather than per IP: the bearer token is the identity here, and an office
    // behind a single NAT would otherwise share - and exhaust - one bucket between everybody.
    // Checked once up front, so a caller already near the limit can overshoot it by at most one
    // request's worth of recipients - deliberate, since re-checking mid-loop would half-send a request.
    if (isRateLimited('share', $callerId)) {
        $shareLimit = rateLimitConfig('share');
        return rateLimitedResponse('share', $callerId,
            "Send limit reached ({$shareLimit['max']} messages per {$shareLimit['window']} minutes). Try again later.");
    }

    $recordId = $payload['recordId'] ?? null;
    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.recordId is required and must be a valid Airtable record id'
        ], 400);
    }

    $recipients = parseShareRecipients($payload['to'] ?? null, $pe_share_max_recipients);
    if (!$recipients['ok']) {
        return $recipients['response'];
    }

    $note = $payload['note'] ?? '';
    if (!is_string($note)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.note must be a string'
        ], 400);
    }
    $note = trim($note);
    if (strlen($note) > $pe_share_max_note_length) {
        return new WP_REST_Response([
            'success' => false,
            'message' => "payload.note must be {$pe_share_max_note_length} characters or fewer"
        ], 400);
    }

    $airtableHelper = new AirtableHelper();
    $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
    if (!$owned['ok']) {
        return $owned['response'];
    }

    $estimateName = trim((string) ($owned['record']['fields']['Name'] ?? ''));
    // the Name is client-supplied at create time, so strip CR/LF here rather than leaning on PHPMailer's
    // own header sanitizing downstream - the guarantee belongs where the value is known to be untrusted
    $subjectName = trim(preg_replace('/[\r\n]+/', ' ', $estimateName));
    $subject = 'Project Estimate' . ($subjectName !== '' ? ': ' . $subjectName : '');

    $attachments = [];
    $attachedNames = [];
    // one running total across the client PDF and any stored files, so the message as a whole is capped
    // rather than each source being capped independently and the sum going unchecked
    $totalBytes = 0;

    // THE PDF REPORT, supplied by the client. The estimate is stored on Airtable as serialized JSON; the
    // app renders that into a PDF on the device and hands it here, so the report itself is never stored
    // server-side and this endpoint never generates one.
    //
    // This is the one thing a caller may put INTO an outgoing message, so it is bounded on every axis that
    // is cheap to check: the caller must own the estimate (gate above), the bytes must really be a PDF
    // (magic header, not the client's word), the size is capped, the filename is stripped of anything that
    // could break a header, and the send is still rate limited per account. Everything that makes the mail
    // look like it came from us - From, Reply-To, subject, body - stays server-built and is not settable.
    if (isset($payload['file'])) {
        $pdf = $payload['file'];
        if (!is_array($pdf) || !is_string($pdf['base64'] ?? null) || $pdf['base64'] === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'payload.file requires base64 (and optionally filename) - omit payload.file entirely to send without the report'
            ], 400);
        }

        $pdfBytes = base64_decode($pdf['base64'], true);
        // the magic header is the actual check - a client-declared contentType proves nothing
        if ($pdfBytes === false || strncmp($pdfBytes, '%PDF-', 5) !== 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'payload.file must be a base64-encoded PDF - nothing was sent!'
            ], 422);
        }
        if (strlen($pdfBytes) > $pe_share_max_pdf_bytes) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'The PDF exceeds the ' . round($pe_share_max_pdf_bytes / 1048576) . ' MB limit - nothing was sent!'
            ], 413);
        }

        $pdfName = preg_replace('/[\r\n"]/', '', (string) ($pdf['filename'] ?? ''));
        $pdfName = trim($pdfName);
        if ($pdfName === '') {
            $pdfName = 'estimate.pdf';
        }
        // guarantee the extension: recipients' mail clients open by extension, and a report named
        // "estimate" with no suffix is a file nobody can double-click
        if (strtolower(substr($pdfName, -4)) !== '.pdf') {
            $pdfName .= '.pdf';
        }

        $attachments[] = ['filename' => $pdfName, 'type' => 'application/pdf', 'bytes' => $pdfBytes];
        $attachedNames[] = $pdfName;
        $totalBytes += strlen($pdfBytes);
    }

    // Optionally ALSO attach the record's stored files - the serialized estimate JSON, not the report.
    // Off by default: a recipient wants the PDF, and raw JSON is rarely what they can use. The bytes are
    // fetched through the same ownership-gated path /files/download uses, so nothing here exposes a
    // record the caller could not already read.
    if (!empty($payload['includeFiles'])) {
        foreach ($owned['record']['fields']['Attachments'] ?? [] as $file) {
            $fetched = fetchAttachmentBytes($file['url']);
            // fail loudly instead of quietly emailing an estimate without the files that were asked for
            if (!$fetched['ok']) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to fetch an attachment from storage - nothing was sent!',
                    'error' => $fetched['error']
                ], 502);
            }

            $totalBytes += strlen($fetched['bytes']);
            if ($totalBytes > $pe_share_max_attachment_bytes) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Attachments exceed the ' . round($pe_share_max_attachment_bytes / 1048576) . ' MB limit - nothing was sent!'
                ], 413);
            }

            // these are the record's STORED files, i.e. the serialized estimate JSON - not the PDF report,
            // which the client hands to this endpoint directly (see payload.file below)
            $safeFilename = preg_replace('/[\r\n"]/', '', $file['filename'] ?? 'estimate.json');
            if ($safeFilename === '') {
                $safeFilename = 'estimate.json';
            }
            $attachments[] = [
                'filename' => $safeFilename,
                'type' => $file['type'] ?? 'application/json',
                'bytes' => $fetched['bytes'],
            ];
            $attachedNames[] = $safeFilename;
        }
    }

    $mailService = new MailService();
    $body = $mailService->renderEstimateShare($estimateName, $note, $authUser['name'] ?? '', $attachedNames);
    $config = ['from' => $pe_email_from, 'replyTo' => $pe_email_reply_to];

    $sent = [];
    $failed = [];
    foreach ($recipients['to'] as $address) {
        $result = $mailService->send($address, $subject, $body, $config, $attachments);
        if ($result['ok']) {
            $sent[] = $address;
            registerEmailSend($callerId);
        } else {
            // the reason is both logged and returned - a send that fails silently is a send nobody fixes
            $failed[] = ['to' => $address, 'error' => $result['error']];
            error_log("[ProjectEstimator] /estimates/share to {$address} failed: {$result['error']}");
        }
    }

    if (empty($sent)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to send the project estimate!',
            'data' => ['sent' => [], 'failed' => $failed]
        ], 502);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Project estimate sent!',
        'data' => [
            'estimateId' => $recordId,
            'sent' => $sent,
            'failed' => $failed, // populated when some recipients went through and others did not
            'attached' => $attachedNames,
        ],
    ], 200);
}
//-----------------------------END: PROJECT ESTIMATES------------------------------------------ keep

//-----------------------------START: TEMPORARY MIGRATION-------------------------------------- remove after use
// payload: { setOwner: [recordId, ...], delete: [recordId, ...] }
// setOwner assigns the CALLER as owner of each id, but only if it has no existing valid owner marker
// yet (never overwrites a record someone already legitimately owns). delete removes ids outright -
// for orphaned records nobody can own (e.g. a typo'd Assignee at create time).
function fixLegacyEstimates(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id;
    $authUser = $request->get_param('authUser');
    $payload = $request->get_param('payload');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;

    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    $setOwnerIds = is_array($payload['setOwner'] ?? null) ? $payload['setOwner'] : [];
    $deleteIds = is_array($payload['delete'] ?? null) ? $payload['delete'] : [];
    $airtableHelper = new AirtableHelper();
    $results = ['setOwner' => [], 'delete' => []];

    foreach ($setOwnerIds as $id) {
        if (!isValidAirtableRecordId($id)) {
            $results['setOwner'][] = ['id' => $id, 'ok' => false, 'reason' => 'invalid id'];
            continue;
        }
        $fetch = $airtableHelper->fetchRecords([
            'baseId' => $users_and_projects_baseId,
            'tableId' => $project_estimates_table_id
        ], "RECORD_ID()='{$id}'", ['Notes']);
        if (!$fetch['success'] || empty($fetch['result'])) {
            $results['setOwner'][] = ['id' => $id, 'ok' => false, 'reason' => 'not found'];
            continue;
        }
        $existingNotes = $fetch['result'][0]['fields']['Notes'] ?? '';
        if (extractEstimateOwnerId($existingNotes) !== null) {
            $results['setOwner'][] = ['id' => $id, 'ok' => false, 'reason' => 'already has a valid owner marker'];
            continue;
        }
        $patch = $airtableHelper->patchRecords([[
            'id' => $id,
            'fields' => ['Notes' => buildNotesWithOwner($callerId, $existingNotes)]
        ]], ['baseId' => $users_and_projects_baseId, 'tableId' => $project_estimates_table_id]);
        $results['setOwner'][] = [
            'id' => $id,
            'ok' => $patch['success'],
            'reason' => $patch['success'] ? 'patched' : ($patch['error'] ?? 'patch failed')
        ];
    }

    foreach ($deleteIds as $id) {
        if (!isValidAirtableRecordId($id)) {
            $results['delete'][] = ['id' => $id, 'ok' => false, 'reason' => 'invalid id'];
            continue;
        }
        $delete = $airtableHelper->deleteRecords([$id], [
            'baseId' => $users_and_projects_baseId,
            'tableId' => $project_estimates_table_id
        ]);
        $results['delete'][] = [
            'id' => $id,
            'ok' => $delete['success'],
            'reason' => $delete['success'] ? 'deleted' : ($delete['error'] ?? 'delete failed')
        ];
    }

    return new WP_REST_Response(['success' => true, 'data' => $results], 200);
}
//-----------------------------END: TEMPORARY MIGRATION----------------------------------------- remove after use

//-----------------------------START: PROJECT ESTIMATOR FILES--------------------------------- keep
// PROJECT ESTIMATES TABLE, Attachments column (fldFYnlJOQO3Ge6cJ) only - list/upload/delete JSON files on an existing record

// Airtable record/attachment ids are always "rec"/"att" + 14 alphanumeric chars - reject anything else
// before it reaches a filterByFormula string, since that string is built by direct interpolation
function isValidAirtableRecordId($id, $prefix = 'rec') {
    return is_string($id) && preg_match('/^' . $prefix . '[a-zA-Z0-9]{14}$/', $id) === 1;
}

// Pulls an attachment's bytes from Airtable's presigned url, so the file stays behind our own ownership
// check rather than the link alone. Shared by /files/download and /estimates/share.
// Returns ['ok' => true, 'bytes' => string], or ['ok' => false, 'error' => string].
function fetchAttachmentBytes($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $bytes = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($bytes === false || $httpCode !== 200) {
        return ['ok' => false, 'error' => $curlError ?: "Upstream returned HTTP {$httpCode}"];
    }
    return ['ok' => true, 'bytes' => $bytes];
}

//-----------------------------SESSION MANAGEMENT / MAINTENANCE-------------------------- keep
// This block is the operator surface: who is logged in, from where, and the ability to cut a session off
// or take the whole API down. It is gated by ONE shared key rather than by a bearer token, deliberately -
// its whole purpose is to work when you do not trust the tokens that are out there.

// Gate for every operator endpoint. Returns ['ok' => true] or ['ok' => false, 'response' => WP_REST_Response],
// matching the convention loadOwnedEstimate/parseRowLimit already use.
function requireSessionKey(WP_REST_Request $request) {
    // the budget/window are not pulled in: rateLimitConfig('operator') resolves them, so an override applies
    global $pe_session_key_constant, $pe_session_key_header;

    // fail closed: an unconfigured key must never mean "no key required"
    if (!defined($pe_session_key_constant) || trim((string) constant($pe_session_key_constant)) === '') {
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'Session management is not configured on this server.',
            'error' => 'session_key_unset'
        ], 503)];
    }

    $ip = getClientIp();
    if (isRateLimited('operator', $ip)) {
        return ['ok' => false, 'response' => rateLimitedResponse('operator', $ip, 'Too many failed attempts. Try again later.')];
    }

    $supplied = $request->get_header($pe_session_key_header);
    // hash_equals is timing-safe; == would leak the key one character at a time to a patient caller
    if (!is_string($supplied) || !hash_equals((string) constant($pe_session_key_constant), $supplied)) {
        $attempts = registerRateLimitFailure('operator', $ip);
        return ['ok' => false, 'response' => new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid session key!',
            // from the resolved config, not the file global, or an override would report a stale count
            'data' => ['attemptsLeft' => max(0, (int) rateLimitConfig('operator')['max'] - $attempts)]
        ], 401)];
    }

    clearRateLimitFailures('operator', $ip);
    return ['ok' => true];
}

// SESSION INDEX. WordPress has no API to enumerate transients, so a login cannot be discovered from the
// session transient alone - it can only be looked up once you already know the token. This index is what
// makes "who is logged in" answerable at all. It is options rather than a wp_options LIKE query so it keeps
// working if an object cache (Redis/Memcached) is ever installed, at which point transients leave the DB.
//
// Stored ONE ROW PER ACCOUNT (`pe_sessions_<recordId>`), not one shared row, for two reasons that only
// matter once users can see their own list:
//   1. The cap has to be per account. A global cap evicts by age across everybody, so one person's logins
//      could silently drop another person's device from their own list - a live session, no error, gone.
//   2. Two people logging in at once touch different rows, so they cannot lose each other's entry to a
//      last-write-wins race. Two logins on the SAME account still can, which is rare and self-correcting.
// The cost is that "every session" needs to know which rows exist, hence the small registry below.

// Registry of accounts holding at least one session. Options cannot be enumerated by prefix any more than
// transients can, so without this the operator view would have no way to find the per-account rows.
function sessionAccountsRead() {
    global $pe_session_accounts_option;
    $accounts = get_option($pe_session_accounts_option, []);
    return is_array($accounts) ? array_values(array_unique($accounts)) : [];
}

function accountSessionsOption($recordId) {
    global $pe_session_option_prefix;
    return $pe_session_option_prefix . $recordId;
}

function accountSessionsRead($recordId) {
    if (!is_string($recordId) || $recordId === '') {
        return [];
    }
    $rows = get_option(accountSessionsOption($recordId), []);
    return is_array($rows) ? $rows : [];
}

// Writes one account's rows and keeps the registry in step. An emptied account drops its option entirely
// rather than leaving an empty row behind, and the registry is only rewritten when membership changed.
function accountSessionsWrite($recordId, array $rows) {
    global $pe_session_accounts_option;
    if (!is_string($recordId) || $recordId === '') {
        return;
    }
    $accounts = sessionAccountsRead();
    $listed = in_array($recordId, $accounts, true);

    if (empty($rows)) {
        delete_option(accountSessionsOption($recordId));
        if ($listed) {
            update_option($pe_session_accounts_option, array_values(array_diff($accounts, [$recordId])), false);
        }
        return;
    }

    update_option(accountSessionsOption($recordId), $rows, false);
    if (!$listed) {
        $accounts[] = $recordId;
        update_option($pe_session_accounts_option, $accounts, false);
    }
}

// One account's still-live sessions, pruning dead entries on read. Expiry deletes the transient but cannot
// touch this index, so pruning here is what stops the two drifting apart.
function accountSessionsLive($recordId) {
    global $pe_auth_transient_prefix;
    $rows = accountSessionsRead($recordId);
    $live = [];
    foreach ($rows as $id => $entry) {
        $token = $entry['token'] ?? '';
        if ($token !== '' && get_transient($pe_auth_transient_prefix . $token) !== false) {
            $live[$id] = $entry;
        }
    }
    if (count($live) !== count($rows)) {
        accountSessionsWrite($recordId, $live);
    }
    return $live;
}

// Derives the id shown to operators. The raw token is never returned by any endpoint - an operator
// listing logins must not become a way to take them over.
function sessionId($token) {
    return substr(hash('sha256', $token), 0, 16);
}

// Moves the pre-2026-08-03 single shared index row into per-account rows. Runs once, on the next login.
//
// The legacy option is DEPRECATED, not deleted: entries are copied, not moved, so nothing is destroyed if
// the migration turns out to be wrong. Sessions themselves are never touched either way - they live in
// transients, and the index is only how they are found.
//
// Entries predating the recordId field cannot be filed under an account (the recordId is simply not
// recorded, and resolving it would need an Airtable lookup per entry). Those are left in the legacy row
// and reported, rather than silently dropped: their sessions keep working and reappear on next login.
function migrateLegacySessionIndex() {
    global $pe_session_index_option, $pe_session_index_migrated_option;

    if (!empty(get_option($pe_session_index_migrated_option, false))) {
        return;
    }

    $legacy = get_option($pe_session_index_option, null);
    if (!is_array($legacy)) {
        update_option($pe_session_index_migrated_option, 1, false); // nothing to do; do not look again
        return;
    }

    $byAccount = [];
    $unattributable = 0;
    foreach ($legacy as $id => $entry) {
        $recordId = is_array($entry) ? ($entry['recordId'] ?? '') : '';
        if (!is_string($recordId) || $recordId === '') {
            $unattributable++;
            continue;
        }
        $byAccount[$recordId][$id] = $entry;
    }

    foreach ($byAccount as $recordId => $rows) {
        accountSessionsWrite($recordId, array_merge(accountSessionsRead($recordId), $rows));
    }

    update_option($pe_session_index_migrated_option, 1, false);
    error_log(sprintf(
        '[ProjectEstimator] session index migrated: %d entries across %d account(s); %d could not be attributed and remain in the deprecated %s option, which was left in place.',
        count($legacy) - $unattributable, count($byAccount), $unattributable, $pe_session_index_option
    ));
}

// Records a new login. The raw token is stored because delete_transient() is keyed by it and there is no
// other way to terminate a session. This adds no exposure: wp_options already holds every live token in
// plaintext as the option_name '_transient_pe_auth_<token>'.
function sessionIndexAdd($token, array $meta) {
    global $pe_auth_transient_prefix, $pe_session_index_option, $pe_session_max_devices;

    $recordId = $meta['recordId'] ?? '';
    if (!is_string($recordId) || $recordId === '') {
        return; // nothing to file it under; the login still succeeds, it is just not listable
    }

    migrateLegacySessionIndex();
    $rows = accountSessionsLive($recordId);

    // A device logging in again SUPERSEDES its previous session instead of adding a row. Without this, ten
    // logins from one phone are ten near-identical entries the user cannot tell apart - and the old tokens
    // stay valid, so "terminate this device" would leave the earlier ones alive.
    $deviceId = $meta['deviceId'] ?? '';
    if (is_string($deviceId) && $deviceId !== '') {
        foreach ($rows as $id => $entry) {
            if (($entry['deviceId'] ?? '') === $deviceId) {
                delete_transient($pe_auth_transient_prefix . ($entry['token'] ?? ''));
                unset($rows[$id]);
            }
        }
    }

    $rows[sessionId($token)] = array_merge($meta, ['token' => $token]);

    // Cap is PER ACCOUNT, oldest first - so no one else's logins can evict a device from your list.
    if (count($rows) > $pe_session_max_devices) {
        uasort($rows, function ($a, $b) {
            return ($a['loginAt'] ?? 0) <=> ($b['loginAt'] ?? 0);
        });
        $rows = array_slice($rows, count($rows) - $pe_session_max_devices, null, true);
    }

    accountSessionsWrite($recordId, $rows);
}

// Drops one session's entry. Callers holding the session pass its recordId; the registry scan is only
// reached for entries written before recordId was recorded, and costs one option read per account.
function sessionIndexForget($token, $recordId = '') {
    $id = sessionId($token);
    $candidates = (is_string($recordId) && $recordId !== '') ? [$recordId] : sessionAccountsRead();

    foreach ($candidates as $candidate) {
        $rows = accountSessionsRead($candidate);
        if (isset($rows[$id])) {
            unset($rows[$id]);
            accountSessionsWrite($candidate, $rows);
            return;
        }
    }
}

// Every live session across every account - the operator view. Costs one option read per account with
// sessions, which is why the registry exists rather than scanning anything.
function sessionIndexLive() {
    $all = [];
    foreach (sessionAccountsRead() as $recordId) {
        foreach (accountSessionsLive($recordId) as $id => $entry) {
            if (($entry['recordId'] ?? '') === '') {
                $entry['recordId'] = $recordId; // the row it lives in is authoritative
            }
            $all[$id] = $entry;
        }
    }
    return $all;
}

// Ends every session for one account, optionally sparing one token. Used when a password changes: the
// tokens minted under the old password must stop working, which is the whole point of changing it.
// Returns how many were ended.
function terminateSessionsForAccount($recordId, $exceptToken = '') {
    global $pe_auth_transient_prefix;
    $rows = accountSessionsLive($recordId);
    $ended = 0;

    foreach ($rows as $id => $entry) {
        $token = $entry['token'] ?? '';
        if ($token === '' || ($exceptToken !== '' && $token === $exceptToken)) {
            continue;
        }
        delete_transient($pe_auth_transient_prefix . $token);
        unset($rows[$id]);
        $ended++;
    }

    if ($ended > 0) {
        accountSessionsWrite($recordId, $rows);
    }
    return $ended;
}

// The operator-set TTL wins over the file global, so changing the timeout needs no cPanel upload.
// Returns SECONDS. Three generations of stored override are read, newest first, and each older unit is
// converted rather than ignored - an override set before a unit change must not silently revert to the file
// default. Nothing rewrites the stored value on read: the conversion is cheap and a write here would need
// this function to stop being a pure read.
function sessionTtlSeconds() {
    global $pe_auth_token_ttl_seconds, $pe_session_settings_option;
    $settings = get_option($pe_session_settings_option, []);
    $settings = is_array($settings) ? $settings : [];

    $seconds = $settings['ttlSeconds'] ?? null;
    if ($seconds === null && isset($settings['ttlMinutes'])) {
        $seconds = (int) $settings['ttlMinutes'] * 60;
    }
    if ($seconds === null && isset($settings['ttlDays'])) {
        $seconds = (int) $settings['ttlDays'] * 86400;
    }

    return ($seconds !== null && (int) $seconds > 0) ? (int) $seconds : (int) $pe_auth_token_ttl_seconds;
}

// The same TTL in whole minutes, or null when it cannot be expressed in them. Exists only to keep the
// deprecated ttlMinutes field honest: reporting a 90-second TTL as "1 minute" would be a wrong number in a
// field a client might still be reading, and null at least says "ask ttlSeconds instead".
function sessionTtlMinutesOrNull() {
    $seconds = sessionTtlSeconds();
    return ($seconds % 60 === 0) ? intdiv($seconds, 60) : null;
}

// Current IP-lock mode: operator override wins over the file global, so it can be relaxed without a
// redeploy the moment it proves too aggressive for real mobile clients.
function sessionIpLockMode() {
    global $pe_session_ip_lock, $pe_session_settings_option;
    $settings = get_option($pe_session_settings_option, []);
    $mode = is_array($settings) ? ($settings['ipLock'] ?? null) : null;
    if ($mode === null) {
        $mode = $pe_session_ip_lock;
    }
    // anything unrecognised means 'off' rather than a guess - a typo must not silently lock everyone out
    return in_array($mode, ['exact', 'subnet', 'off'], true) ? $mode : 'off';
}

// Reduces an address to the thing being compared. inet_pton rather than splitting on '.' or ':' because
// compressed IPv6 ('2001:db8::1') has empty groups that string-splitting gets wrong.
function ipNetworkKey($ip, $mode) {
    if ($mode !== 'subnet') {
        return $ip;
    }
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return $ip; // unparseable (or 'unknown') - fall back to comparing it whole
    }
    // 4 bytes = IPv4, keep 3 for a /24; 16 bytes = IPv6, keep 8 for a /64
    $keep = (strlen($packed) === 4) ? 3 : 8;
    return bin2hex(substr($packed, 0, $keep));
}

// true when $current is close enough to $stored under the active mode
function sessionIpAllowed($stored, $current, $mode) {
    if ($mode === 'off') {
        return true;
    }
    // a session minted before the IP was recorded has nothing to compare against; allowing it avoids
    // logging every existing user out the moment this deploys, and they age out within the TTL anyway
    if (!is_string($stored) || $stored === '' || $stored === 'unknown') {
        return true;
    }
    return ipNetworkKey($stored, $mode) === ipNetworkKey($current, $mode);
}

// Human-readable form of a second count, so an operator reading 604800 does not have to divide it themselves.
// Picks the largest unit the value divides into exactly, then falls through to seconds - so 1800 reads as
// "30 minutes" rather than "0.5 hours", and 90 stays "90 seconds" instead of rounding to a wrong "2 minutes".
// The >= guard matters: without it 0 would divide exactly by every unit and come out as "0 weeks".
function formatTtlSeconds($seconds) {
    $seconds = (int) $seconds;
    $units = [604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];

    foreach ($units as $size => $name) {
        if ($seconds >= $size && $seconds % $size === 0) {
            $count = intdiv($seconds, $size);
            return $count . ' ' . $name . ($count === 1 ? '' : 's');
        }
    }
    return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}

//-----------------------------CLIENT APP VERSION-----------------------------------------
// Parses 'Major.Minor.Patch+Build' into [M, m, p, b] for comparison. Missing trailing parts count as 0, so
// '1.4' is 1.4.0+0. Returns null for anything unparseable, which callers treat as "unknown", never as old.
function parseClientVersion($version) {
    if (!is_string($version) || trim($version) === '') {
        return null;
    }
    if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\+(\d+))?$/', trim($version), $m) !== 1) {
        return null;
    }
    return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
}

// -1 if $a is older than $b, 0 if equal, 1 if newer. Build is the last tiebreaker rather than ignored the
// way strict semver would, because in Flutter's pubspec the build number is what increments per release.
function compareClientVersion(array $a, array $b) {
    for ($i = 0; $i < 4; $i++) {
        if ($a[$i] !== $b[$i]) {
            return ($a[$i] < $b[$i]) ? -1 : 1;
        }
    }
    return 0;
}

// Resolves the version thresholds: file defaults with per-key operator overrides from the pe_versions
// option layered on top, so a threshold can be moved without a redeploy. Same shape as rateLimitConfig().
function versionConfig() {
    global $pe_versions_option, $pe_api_release, $pe_version_mode,
        $pe_client_min_version, $pe_client_recommended_version,
        $pe_api_min_version, $pe_api_recommended_version;

    $config = [
        'apiRelease' => (string) $pe_api_release,
        'clientMin' => (string) $pe_client_min_version,
        'clientRecommended' => (string) $pe_client_recommended_version,
        'apiMin' => (string) $pe_api_min_version,
        'apiRecommended' => (string) $pe_api_recommended_version,
        'mode' => (string) $pe_version_mode,
    ];

    $stored = get_option($pe_versions_option, []);
    if (!is_array($stored)) {
        return $config;
    }
    foreach ($config as $key => $default) {
        if (!isset($stored[$key]) || !is_string($stored[$key])) {
            continue;
        }
        // '' is a meaningful stored value (it disables a threshold), so it is accepted rather than skipped
        $config[$key] = $stored[$key];
    }
    // an unrecognised mode means 'off' rather than a guess - a typo must never start refusing callers
    if (!in_array($config['mode'], ['off', 'warn', 'block'], true)) {
        $config['mode'] = 'off';
    }
    return $config;
}

// The one band evaluator both checks share. Returns:
//   ['band' => 'disabled'|'unknown'|'danger'|'warn'|'good', 'sent', 'min', 'recommended']
// 'disabled' when neither threshold is set, 'unknown' when the caller sent nothing parseable. Neither is a
// judgement about the caller, and neither is ever refused.
function versionBand($sentRaw, $minRaw, $recommendedRaw) {
    $min = parseClientVersion($minRaw);
    $recommended = parseClientVersion($recommendedRaw);
    $result = ['sent' => trim((string) $sentRaw), 'min' => (string) $minRaw, 'recommended' => (string) $recommendedRaw];

    if ($min === null && $recommended === null) {
        return array_merge($result, ['band' => 'disabled']);
    }

    $sent = parseClientVersion($sentRaw);
    if ($sent === null) {
        return array_merge($result, ['band' => 'unknown']);
    }

    if ($min !== null && compareClientVersion($sent, $min) < 0) {
        return array_merge($result, ['band' => 'danger']);
    }
    if ($recommended !== null && compareClientVersion($sent, $recommended) < 0) {
        return array_merge($result, ['band' => 'warn']);
    }
    return array_merge($result, ['band' => 'good']);
}

// The caller's app version against the client thresholds.
function clientVersionStatus(WP_REST_Request $request) {
    global $pe_client_version_header;
    $config = versionConfig();
    return versionBand(
        $request->get_header($pe_client_version_header),
        $config['clientMin'],
        $config['clientRecommended']
    );
}

// The API version the caller says it was built against, so an old client on a newer API is visible before
// it misbehaves. Separate from the client's own app version: the app can be current while its integration
// against this API is not, and vice versa.
function apiVersionStatus(WP_REST_Request $request) {
    global $pe_api_version_header;
    $config = versionConfig();
    return versionBand(
        $request->get_header($pe_api_version_header),
        $config['apiMin'],
        $config['apiRecommended']
    );
}

// Whichever of the two checks is worse, so the gate and the advisory headers agree on one verdict.
// Ordering is deliberate: danger outranks warn, and unknown/disabled never outrank either.
function worstVersionBand(array $bands) {
    foreach (['danger', 'warn', 'unknown'] as $band) {
        foreach ($bands as $candidate) {
            if ($candidate === $band) {
                return $band;
            }
        }
    }
    return in_array('good', $bands, true) ? 'good' : 'disabled';
}

// Normalizes the optional client-supplied device identity from a login body.
// Returns ['deviceId' => string, 'deviceName' => string]; both '' when absent.
// Deliberately lenient: a malformed or missing deviceId costs grouping, not the login, so it degrades to
// the old one-row-per-login behaviour rather than refusing to authenticate anyone on an older app build.
function parseDeviceIdentity(WP_REST_Request $request) {
    $id = $request->get_param('deviceId');
    $name = $request->get_param('deviceName');

    // capped and character-restricted because it becomes part of nothing dangerous, but it IS stored and
    // echoed back to the user's own device list - so keep it boring rather than trusting its shape
    $id = is_string($id) ? substr(preg_replace('/[^A-Za-z0-9\-_]/', '', $id), 0, 64) : '';
    $name = is_string($name) ? substr(sanitize_text_field($name), 0, 60) : '';

    return ['deviceId' => $id, 'deviceName' => trim($name)];
}

// A best-effort device label from the User-Agent. IP alone is a poor device identifier - phones change
// IP constantly and CGNAT puts many users behind one - so both are recorded and neither is trusted.
function clientDeviceLabel() {
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($agent === '') {
        return 'unknown';
    }
    return substr(sanitize_text_field($agent), 0, 180);
}

// Current maintenance toggles, with every key guaranteed present so no caller has to guard on isset.
function maintenanceState() {
    global $pe_maintenance_option;
    $stored = get_option($pe_maintenance_option, []);
    $stored = is_array($stored) ? $stored : [];
    return [
        'enabled' => !empty($stored['enabled']),
        'blockAuth' => !empty($stored['blockAuth']),
        'blockReads' => !empty($stored['blockReads']),
        'blockWrites' => !empty($stored['blockWrites']),
        'message' => isset($stored['message']) ? (string) $stored['message'] : '',
        'retryAfterSeconds' => isset($stored['retryAfterSeconds']) ? (int) $stored['retryAfterSeconds'] : 3600,
        'updatedAt' => isset($stored['updatedAt']) ? (string) $stored['updatedAt'] : ''
    ];
}

// Routes that NEITHER gate may ever block - not maintenance, not the read/write throttle. Without this the
// switches become one-way: a maintenance window or a mistyped rate limit would also block the endpoint you
// need in order to undo it, leaving a cPanel re-upload or a phpMyAdmin DELETE as the only way out. /ping is
// here so a gated window can still be told apart from an actually dead server.
function gateExemptRoutes() {
    return [
        '/ping', '/maintenance', '/ratelimits', '/versions', '/session/list', '/session/terminate', '/session/settings', '/auth/logout',
        // /admin/* twins (2026-08-11) - exempt on exactly the same routes their /session|maintenance|ratelimits|versions
        // predecessors already were, so the panel meant to fix a bad window or budget can never be locked out by it
        // either. /admin/estimates/limits, /admin/bookings/limits and /admin/bugs/list are deliberately NOT exempt,
        // matching their pre-migration counterparts, which never were either.
        '/admin/maintenance', '/admin/ratelimits', '/admin/versions',
        '/admin/sessions/list', '/admin/sessions/terminate', '/admin/sessions/settings',
    ];
}

// Which toggle, if any, blocks this request. Returns 'auth' | 'read' | 'write', or null to allow.
// $state is passed in by the gate filter so a request costs one option read, not two.
function maintenanceBlocks($route, $method, array $state = null) {
    global $pe_rest_namespace;
    $state = $state !== null ? $state : maintenanceState();
    if (!$state['enabled']) {
        return null;
    }

    $path = substr($route, strlen('/' . $pe_rest_namespace));
    if (in_array($path, gateExemptRoutes(), true)) {
        return null;
    }

    // auth is checked first: /auth/login and /account/redeem are POSTs, so blockWrites would otherwise
    // swallow them and the blockAuth toggle would never be the reason reported back to the client
    $authRoutes = ['/auth/login', '/account/redeem', '/account/create'];
    if (in_array($path, $authRoutes, true)) {
        return $state['blockAuth'] ? 'auth' : null;
    }

    if ($method === 'GET') {
        return $state['blockReads'] ? 'read' : null;
    }
    return $state['blockWrites'] ? 'write' : null;
}

function listSessions(WP_REST_Request $request) {
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $sessions = [];
    foreach (sessionIndexLive() as $id => $entry) {
        $loginAt = (int) ($entry['loginAt'] ?? 0);
        $expiresAt = (int) ($entry['expiresAt'] ?? 0);
        // token is deliberately omitted - see sessionId(). Epochs are UTC and sent alongside a readable
        // form so a client never has to guess the server's timezone.
        $sessions[] = [
            'sessionId' => $id,
            'username' => $entry['username'] ?? '',
            'name' => $entry['name'] ?? '',
            'ip' => $entry['ip'] ?? 'unknown',
            'device' => $entry['device'] ?? 'unknown',
            'loginAt' => $loginAt,
            'loginAtUtc' => $loginAt > 0 ? gmdate('Y-m-d H:i:s', $loginAt) : '',
            'expiresAt' => $expiresAt,
            'expiresAtUtc' => $expiresAt > 0 ? gmdate('Y-m-d H:i:s', $expiresAt) : ''
        ];
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => ['count' => count($sessions), 'sessions' => $sessions]
    ], 200);
}

// POST /session/terminate - body is { sessionId } or { username } or { all: true }. Exactly one.
function terminateSessions(WP_REST_Request $request) {
    global $pe_auth_transient_prefix;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $sessionIdParam = $request->get_param('sessionId');
    $username = $request->get_param('username');
    $all = filter_var($request->get_param('all'), FILTER_VALIDATE_BOOLEAN);

    $byId = is_string($sessionIdParam) && $sessionIdParam !== '';
    $byUsername = is_string($username) && $username !== '';
    if (($byId ? 1 : 0) + ($byUsername ? 1 : 0) + ($all ? 1 : 0) !== 1) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send exactly one of sessionId, username, or all.'
        ], 400);
    }

    // Grouped by account so each account's row is written at most once, rather than once per matched
    // session - terminating by username or all can hit many entries across several rows.
    $terminated = [];
    foreach (sessionAccountsRead() as $recordId) {
        $rows = accountSessionsLive($recordId);
        $touched = false;

        foreach ($rows as $id => $entry) {
            $matches = $all
                || ($byId && $id === $sessionIdParam)
                || ($byUsername && strcasecmp((string) ($entry['username'] ?? ''), $username) === 0);
            if (!$matches) {
                continue;
            }
            delete_transient($pe_auth_transient_prefix . ($entry['token'] ?? ''));
            unset($rows[$id]);
            $touched = true;
            $terminated[] = ['sessionId' => $id, 'username' => $entry['username'] ?? ''];
        }

        if ($touched) {
            accountSessionsWrite($recordId, $rows);
        }
    }

    if (empty($terminated)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No live session matched.'
        ], 404);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => count($terminated) . ' session(s) terminated.',
        'data' => ['terminated' => $terminated]
    ], 200);
}

//-----------------------------USER-FACING DEVICE MANAGEMENT----------------------------- keep
// GET /session/mine, POST /session/mine/terminate - a user seeing and cutting off their OWN devices,
// without an operator in the loop. Bearer-gated, and scoped to the caller's own recordId from the
// server-side session - NEVER to an id from the request, which would turn "see my devices" into "see
// anyone's" and "sign out my phone" into "sign out anyone's".

// Shapes one index entry for a user's own eyes. The token is never included (see sessionId()), and
// 'current' marks the session making this very request so a UI can label it and avoid self-eviction.
function presentOwnSession($id, array $entry, $currentSessionId) {
    $loginAt = (int) ($entry['loginAt'] ?? 0);
    $expiresAt = (int) ($entry['expiresAt'] ?? 0);
    $deviceName = trim((string) ($entry['deviceName'] ?? ''));

    return [
        'sessionId' => $id,
        'current' => $id === $currentSessionId,
        // deviceName is what the app chose to call itself; the User-Agent label is the fallback, and is
        // the only thing available for a login that predates deviceId
        'deviceName' => $deviceName !== '' ? $deviceName : ($entry['device'] ?? 'unknown'),
        'deviceId' => $entry['deviceId'] ?? '',
        'ip' => $entry['ip'] ?? 'unknown',
        'loginAt' => $loginAt,
        'loginAtUtc' => $loginAt > 0 ? gmdate('Y-m-d H:i:s', $loginAt) : '',
        'expiresAt' => $expiresAt,
        'expiresAtUtc' => $expiresAt > 0 ? gmdate('Y-m-d H:i:s', $expiresAt) : '',
        // same field name and meaning as /auth/login and /currentUser, so one client code path reads all
        // three. Server-computed, so a device with a skewed clock still counts down correctly.
        'expiresInSeconds' => $expiresAt > 0 ? max(0, $expiresAt - time()) : null
    ];
}

function listMySessions(WP_REST_Request $request) {
    $authUser = $request->get_param('authUser');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? '') : '';
    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    $currentSessionId = sessionId((string) getBearerToken($request));
    $sessions = [];
    foreach (accountSessionsLive($callerId) as $id => $entry) {
        $sessions[] = presentOwnSession($id, $entry, $currentSessionId);
    }

    // newest first: the device someone is looking for is usually the one they just used
    usort($sessions, function ($a, $b) {
        return $b['loginAt'] <=> $a['loginAt'];
    });

    return new WP_REST_Response([
        'success' => true,
        'data' => ['count' => count($sessions), 'sessions' => $sessions]
    ], 200);
}

// POST /session/mine/terminate - { sessionId } for one device, or { allOthers: true } for everything except
// the caller's own session. Exactly one. There is deliberately no "all" that includes the caller: signing
// yourself out is what /auth/logout is for, and conflating them makes an accidental self-logout easy.
function terminateMySessions(WP_REST_Request $request) {
    global $pe_auth_transient_prefix;
    $authUser = $request->get_param('authUser');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? '') : '';
    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Could not resolve caller id'
        ], 401);
    }

    $sessionIdParam = $request->get_param('sessionId');
    $allOthers = filter_var($request->get_param('allOthers'), FILTER_VALIDATE_BOOLEAN);
    $byId = is_string($sessionIdParam) && $sessionIdParam !== '';

    if (($byId ? 1 : 0) + ($allOthers ? 1 : 0) !== 1) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send exactly one of sessionId or allOthers.'
        ], 400);
    }

    $currentToken = (string) getBearerToken($request);
    $currentSessionId = sessionId($currentToken);

    // "sign out everywhere else" after a lost phone - the reason this endpoint exists
    if ($allOthers) {
        $ended = terminateSessionsForAccount($callerId, $currentToken);
        return new WP_REST_Response([
            'success' => true,
            'message' => $ended > 0
                ? "{$ended} other device(s) signed out. This one is still signed in."
                : 'No other devices were signed in.',
            'data' => ['terminated' => $ended, 'keptCurrent' => true]
        ], 200);
    }

    // Only the caller's OWN rows are searched, so an id belonging to someone else simply is not found -
    // it cannot be used to sign another account out, and does not reveal that it exists.
    $rows = accountSessionsLive($callerId);
    if (!isset($rows[$sessionIdParam])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No device of yours matches that sessionId.'
        ], 404);
    }

    $isCurrent = $sessionIdParam === $currentSessionId;
    delete_transient($pe_auth_transient_prefix . ($rows[$sessionIdParam]['token'] ?? ''));
    unset($rows[$sessionIdParam]);
    accountSessionsWrite($callerId, $rows);

    return new WP_REST_Response([
        'success' => true,
        // terminating your own session is allowed, but say so plainly: the very next request will 401
        'message' => $isCurrent
            ? 'This device has been signed out - your next request will need a fresh login.'
            : 'Device signed out.',
        'data' => ['terminated' => 1, 'wasCurrent' => $isCurrent]
    ], 200);
}

function getSessionSettings(WP_REST_Request $request) {
    global $pe_auth_token_ttl_seconds;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    global $pe_session_ip_lock;
    $ttl = sessionTtlSeconds();
    $ipLock = sessionIpLockMode();
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'ttlSeconds' => $ttl,
            'ttlHuman' => formatTtlSeconds($ttl),
            'ttlSecondsFileDefault' => (int) $pe_auth_token_ttl_seconds,
            // DEPRECATED, kept so an existing caller keeps reading a number. Null rather than a rounded value
            // when the TTL is not a whole number of minutes - a wrong number is worse than an absent one.
            'ttlMinutes' => sessionTtlMinutesOrNull(),
            'ttlMinutesDeprecated' => 'Use ttlSeconds. ttlMinutes is null when the TTL is not a whole number of minutes.',
            'overridden' => $ttl !== (int) $pe_auth_token_ttl_seconds,
            'ipLock' => $ipLock,
            'ipLockFileDefault' => $pe_session_ip_lock,
            'ipLockMeaning' => $ipLock === 'exact'
                ? 'A session ends as soon as REMOTE_ADDR changes at all - strictest, and the most spurious logouts on mobile.'
                : ($ipLock === 'subnet'
                    ? 'A session survives movement inside the same /24 (IPv4) or /64 (IPv6), and ends outside it.'
                    : 'Sessions are not tied to an address.'),
            'liveSessions' => count(sessionIndexLive())
        ]
    ], 200);
}

// PATCH /session/settings - { ttlSeconds: int } sets the timeout, { ttlSeconds: null } reverts to the file
// default. Seconds because that is what set_transient() takes natively, so nothing has to be multiplied to
// know when a token really dies: 1800 for a browse, 28800 for a shift, 86400 for a day. Existing sessions
// keep the TTL they were minted with - a transient's expiry is fixed when it is written and cannot be
// changed after the fact.
function updateSessionSettings(WP_REST_Request $request) {
    global $pe_session_settings_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    // This setting has had three unit names: ttlDays (original), ttlMinutes (2026-08-02), ttlSeconds
    // (2026-08-05). The older two are DEPRECATED, not removed - still accepted and converted so an existing
    // caller keeps working. Listed newest-first, so the first one present in the request is the winner and a
    // caller migrating is never surprised by a stale field it also happens to be sending.
    $ttlFields = [
        ['name' => 'ttlSeconds', 'seconds' => 1,     'unit' => 'seconds'],
        ['name' => 'ttlMinutes', 'seconds' => 60,    'unit' => 'minutes'],
        ['name' => 'ttlDays',    'seconds' => 86400, 'unit' => 'days'],
    ];
    $deprecated = [];

    $sent = [];
    foreach ($ttlFields as $field) {
        if ($request->has_param($field['name'])) {
            $sent[] = $field;
        }
    }

    if (empty($sent) && !$request->has_param('ipLock')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send ttlSeconds and/or ipLock. Either may be null to revert that one to its file default.'
        ], 400);
    }

    // read-modify-write: these settings share one option row, so replacing it wholesale would silently
    // clear whichever key this request did not mention
    $settings = get_option($pe_session_settings_option, []);
    $settings = is_array($settings) ? $settings : [];
    $changed = [];

    if (!empty($sent)) {
        $winner = $sent[0];
        foreach (array_slice($sent, 1) as $loser) {
            $deprecated[] = "{$loser['name']} was ignored because {$winner['name']} was also sent.";
        }
        if ($winner['seconds'] !== 1) {
            $deprecated[] = "{$winner['name']} is deprecated - send ttlSeconds instead. Your value was multiplied by {$winner['seconds']} and stored as seconds.";
        }

        $value = $request->get_param($winner['name']);
        if ($value === null || $value === '') {
            // every generation of the key must go, or sessionTtlSeconds() would fall back to a leftover
            // ttlMinutes instead of to the file default - a revert that silently reverts to the wrong thing
            foreach ($ttlFields as $field) {
                unset($settings[$field['name']]);
            }
            $changed[] = 'ttlSeconds (reverted to file default)';
        } elseif (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1 || (int) $value < 1) {
            return new WP_REST_Response([
                'success' => false,
                'message' => "{$winner['name']} must be a positive integer ({$winner['unit']}), or null to revert to the file default."
            ], 400);
        } else {
            // always stored canonically as ttlSeconds, whichever field it arrived in
            foreach ($ttlFields as $field) {
                unset($settings[$field['name']]);
            }
            $settings['ttlSeconds'] = (int) $value * $winner['seconds'];
            $changed[] = ($winner['seconds'] === 1)
                ? 'ttlSeconds'
                : "ttlSeconds (from {$winner['name']} x {$winner['seconds']})";
        }
    }

    if ($request->has_param('ipLock')) {
        $ipLock = $request->get_param('ipLock');
        if ($ipLock === null || $ipLock === '') {
            unset($settings['ipLock']);
            $changed[] = 'ipLock (reverted to file default)';
        } elseif (!is_string($ipLock) || !in_array($ipLock, ['exact', 'subnet', 'off'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'ipLock must be one of: exact, subnet, off - or null to revert to the file default.'
            ], 400);
        } else {
            $settings['ipLock'] = $ipLock;
            $changed[] = 'ipLock';
        }
    }

    if (empty($settings)) {
        delete_option($pe_session_settings_option);
    } else {
        update_option($pe_session_settings_option, $settings, false);
    }

    $ttl = sessionTtlSeconds();
    $mode = sessionIpLockMode();
    $data = [
        'changed' => $changed,
        'ttlSeconds' => $ttl,
        'ttlHuman' => formatTtlSeconds($ttl),
        'ttlMinutes' => sessionTtlMinutesOrNull(), // DEPRECATED; null when not a whole number of minutes
        'ipLock' => $mode,
        // both only affect sessions minted from now on, which is the thing most likely to be misread
        'appliesTo' => 'ttlSeconds affects sessions created from now on; ipLock affects the next request every live session makes'
    ];

    $warnings = [];
    // a token cannot be revoked except by /session/terminate, so a very long TTL widens that window
    if ($ttl > 2592000) {
        $warnings[] = 'TTL is over 30 days. Tokens expire only on this TTL or an explicit /session/terminate, so this is how long a leaked token stays usable.';
    }
    // A very short TTL is usually a unit mix-up rather than an intention: this field took MINUTES until
    // 2026-08-05, so 30 (half an hour), 60 (an hour) and 480 (a shift, straight out of the old docs) are all
    // values someone types meaning minutes. Threshold is 300 rather than 60 so 60 itself is caught - the most
    // likely mistake of the lot. Only a warning, so a deliberate 2-minute session is merely told twice.
    if ($ttl < 300) {
        $warnings[] = "TTL is only {$ttl} seconds (" . formatTtlSeconds($ttl) . "). This field is SECONDS since 2026-08-05 - if you meant minutes, multiply by 60. Ignore this if a very short session is intended.";
    }
    // said plainly because this is the setting most likely to generate support tickets
    if ($mode === 'exact') {
        $warnings[] = 'ipLock=exact ends a session the moment REMOTE_ADDR changes. On mobile clients that happens routinely (Wi-Fi to cellular, tower handoff, carrier CGNAT reassignment), so expect users to be logged out mid-task. Use subnet if that proves too aggressive.';
    }
    if (!empty($warnings)) {
        $data['warnings'] = $warnings;
    }
    if (!empty($deprecated)) {
        $data['deprecated'] = $deprecated;
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Session settings updated: ' . implode(', ', $changed) . '.',
        'data' => $data
    ], 200);
}

function getRateLimits(WP_REST_Request $request) {
    global $pe_rate_limits_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $stored = get_option($pe_rate_limits_option, []);
    $stored = is_array($stored) ? $stored : [];
    $defaults = rateLimitDefaults();
    $buckets = [];

    foreach ($defaults as $bucket => $default) {
        $effective = rateLimitConfig($bucket);
        $buckets[$bucket] = [
            'max' => (int) $effective['max'],
            'window' => (int) $effective['window'],
            'mode' => $effective['mode'],   // failures = sliding window, requests = fixed window
            'scope' => $effective['scope'], // what the counter is keyed on
            'enabled' => (int) $effective['max'] > 0,
            'fileDefault' => ['max' => (int) $default['max'], 'window' => (int) $default['window']],
            'overridden' => isset($stored[$bucket])
        ];
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'buckets' => $buckets,
            'exemptRoutes' => gateExemptRoutes(),
            'notes' => [
                'mode' => "failures re-stamps the window on every hit (sustained guessing stays locked out); requests keeps the original deadline so the budget resets on schedule",
                'max0' => 'a max of 0 disables that bucket entirely',
                'readWrite' => "the read and write buckets apply to every non-exempt route via the dispatch gate; the others are checked inside their own handler"
            ]
        ]
    ], 200);
}

// PATCH /ratelimits - body is { "<bucket>": { "max": int, "window": int }, ... }. Only the buckets and keys
// present are changed. mode and scope are deliberately not settable: they change what a counter MEANS, and
// flipping one without changing any number would silently alter behaviour.
function updateRateLimits(WP_REST_Request $request) {
    global $pe_rate_limits_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $known = array_keys(rateLimitDefaults());
    $stored = get_option($pe_rate_limits_option, []);
    $stored = is_array($stored) ? $stored : [];
    $changed = [];

    foreach ($known as $bucket) {
        if (!$request->has_param($bucket)) {
            continue;
        }
        $incoming = $request->get_param($bucket);

        // null clears this bucket's override and returns it to the file default
        if ($incoming === null || $incoming === '') {
            unset($stored[$bucket]);
            $changed[] = $bucket;
            continue;
        }
        if (!is_array($incoming)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => "{$bucket} must be an object like {\"max\": 5, \"window\": 15}, or null to clear it."
            ], 400);
        }

        foreach (['max', 'window'] as $key) {
            if (!isset($incoming[$key])) {
                continue;
            }
            $value = $incoming[$key];
            // max may be 0 (disable the bucket); window may not, since a zero-minute window is meaningless
            $floor = ($key === 'max') ? 0 : 1;
            if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1 || (int) $value < $floor) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "{$bucket}.{$key} must be an integer >= {$floor}."
                ], 400);
            }
            $stored[$bucket][$key] = (int) $value;
            $changed[] = "{$bucket}.{$key}";
        }
    }

    if (empty($changed)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send at least one known bucket: ' . implode(', ', $known) . '.'
        ], 400);
    }

    if (empty($stored)) {
        delete_option($pe_rate_limits_option);
    } else {
        update_option($pe_rate_limits_option, $stored, true);
    }

    // existing counters keep the deadline they were created with, so a change is not retroactive
    $effective = [];
    foreach ($known as $bucket) {
        $config = rateLimitConfig($bucket);
        $effective[$bucket] = ['max' => (int) $config['max'], 'window' => (int) $config['window']];
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Rate limits updated - counters already running keep their current window.',
        'data' => ['changed' => $changed, 'effective' => $effective]
    ], 200);
}

function getVersions(WP_REST_Request $request) {
    global $pe_versions_option, $pe_client_version_header, $pe_api_version_header;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $stored = get_option($pe_versions_option, []);
    $stored = is_array($stored) ? $stored : [];
    $config = versionConfig();

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'apiRelease' => $config['apiRelease'],
            'mode' => $config['mode'],
            'client' => [
                'header' => $pe_client_version_header,
                'min' => $config['clientMin'],
                'recommended' => $config['clientRecommended'],
                'enabled' => $config['clientMin'] !== '' || $config['clientRecommended'] !== ''
            ],
            'api' => [
                'header' => $pe_api_version_header,
                'min' => $config['apiMin'],
                'recommended' => $config['apiRecommended'],
                'enabled' => $config['apiMin'] !== '' || $config['apiRecommended'] !== ''
            ],
            'overridden' => array_keys($stored),
            'notes' => [
                'bands' => 'good = at/above recommended; warn = at/above min but below recommended; danger = below min. unknown = caller sent nothing parseable, disabled = no thresholds set. Neither unknown nor disabled is ever refused.',
                'mode' => "off = no check; warn = advisory headers only; block = 'danger' callers get 426. Only block can refuse anyone.",
                'format' => 'Major.Minor.Patch+Build, e.g. 1.4.2+87. Build is the final tiebreaker; a pre-release suffix reads as unknown rather than being misparsed.',
                'exempt' => 'Routes in exemptRoutes are never version-blocked, so /ping and this endpoint stay reachable to diagnose and undo a bad floor.'
            ],
            'exemptRoutes' => gateExemptRoutes()
        ]
    ], 200);
}

// PATCH /versions - body may carry any of: apiRelease, mode, clientMin, clientRecommended, apiMin,
// apiRecommended. Only the keys present are changed; send '' to disable a threshold, or null to drop the
// override and fall back to the file default.
function updateVersions(WP_REST_Request $request) {
    global $pe_versions_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $stored = get_option($pe_versions_option, []);
    $stored = is_array($stored) ? $stored : [];
    $versionKeys = ['apiRelease', 'clientMin', 'clientRecommended', 'apiMin', 'apiRecommended'];
    $changed = [];

    foreach ($versionKeys as $key) {
        if (!$request->has_param($key)) {
            continue;
        }
        $value = $request->get_param($key);

        if ($value === null) {
            unset($stored[$key]);
            $changed[] = "{$key} (reverted to file default)";
            continue;
        }
        if (!is_string($value)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => "{$key} must be a version string like 1.4.2+87, '' to disable it, or null to revert to the file default."
            ], 400);
        }
        // '' disables a threshold; anything else must actually parse, or a typo would silently disable it
        if ($value !== '' && parseClientVersion($value) === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => "{$key} must be Major.Minor.Patch+Build (e.g. 1.4.2+87). Got: {$value}"
            ], 400);
        }
        $stored[$key] = $value;
        $changed[] = $key;
    }

    if ($request->has_param('mode')) {
        $mode = $request->get_param('mode');
        if ($mode === null) {
            unset($stored['mode']);
            $changed[] = 'mode (reverted to file default)';
        } elseif (!is_string($mode) || !in_array($mode, ['off', 'warn', 'block'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'mode must be one of: off, warn, block - or null to revert to the file default.'
            ], 400);
        } else {
            $stored['mode'] = $mode;
            $changed[] = 'mode';
        }
    }

    if (empty($changed)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send at least one of: ' . implode(', ', $versionKeys) . ', mode.'
        ], 400);
    }

    if (empty($stored)) {
        delete_option($pe_versions_option);
    } else {
        update_option($pe_versions_option, $stored, true);
    }

    $config = versionConfig();
    $warnings = [];

    // Order matters here: min must not sit above recommended, or every caller between them lands in
    // 'danger' while the config reads as though they should only be warned.
    foreach ([['client', 'clientMin', 'clientRecommended'], ['api', 'apiMin', 'apiRecommended']] as $pair) {
        $min = parseClientVersion($config[$pair[1]]);
        $rec = parseClientVersion($config[$pair[2]]);
        if ($min !== null && $rec !== null && compareClientVersion($min, $rec) > 0) {
            $warnings[] = "{$pair[0]}: min ({$config[$pair[1]]}) is ABOVE recommended ({$config[$pair[2]]}), so nothing can ever land in the warn band - callers below min go straight to danger.";
        }
    }
    if ($config['mode'] === 'block') {
        $warnings[] = 'mode=block REFUSES callers in the danger band with 426. Every install below the floor stops working immediately. Exempt routes (including /ping and /versions) stay reachable so this can be undone.';
    }

    $data = ['changed' => $changed, 'effective' => $config];
    if (!empty($warnings)) {
        $data['warnings'] = $warnings;
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Version settings updated: ' . implode(', ', $changed) . '.',
        'data' => $data
    ], 200);
}

// OPERATOR: read the active-estimate cap. Session-key gated like the rest of this surface - the cap is a
// staffing decision, and a user able to raise their own would not have a cap at all.
function getEstimateLimits(WP_REST_Request $request) {
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $limits = estimateLimitsConfig();

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'maxActiveEstimates' => $limits['maxActiveEstimates'],
            'fileDefault' => $limits['fileDefault'],
            'overridden' => $limits['overridden'],
            'enabled' => $limits['enabled'],
            'counts' => 'Estimates owned by one account whose Status is not Done. A record with no Status set counts as active.',
            // the misreading worth heading off: a lowered cap does not reach back and close anything
            'appliesTo' => 'New estimates only. Lowering the cap never closes or deletes anything, so an account can sit above a freshly lowered cap until it works the difference off.'
        ]
    ], 200);
}

// OPERATOR: move the cap without a redeploy. Only maxActiveEstimates is settable; null reverts to the file
// default, and 0 means no cap at all rather than "nobody may create anything".
function updateEstimateLimits(WP_REST_Request $request) {
    global $pe_estimate_limits_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    if (!$request->has_param('maxActiveEstimates')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send maxActiveEstimates. Use 0 for no cap, or null to revert to the file default.'
        ], 400);
    }

    $value = $request->get_param('maxActiveEstimates');
    $stored = get_option($pe_estimate_limits_option, []);
    $stored = is_array($stored) ? $stored : [];

    if ($value === null || $value === '') {
        unset($stored['maxActiveEstimates']);
        $changed = 'maxActiveEstimates (reverted to file default)';
    } elseif (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
        // a negative or non-numeric cap is rejected rather than clamped: silently turning -1 into 0 would
        // read back as "no cap" to whoever set it expecting the opposite
        return new WP_REST_Response([
            'success' => false,
            'message' => 'maxActiveEstimates must be a non-negative integer (0 = no cap), or null to revert to the file default.'
        ], 400);
    } else {
        $stored['maxActiveEstimates'] = (int) $value;
        $changed = 'maxActiveEstimates';
    }

    if (empty($stored)) {
        delete_option($pe_estimate_limits_option);
    } else {
        update_option($pe_estimate_limits_option, $stored, false);
    }

    $limits = estimateLimitsConfig();
    $data = [
        'changed' => [$changed],
        'maxActiveEstimates' => $limits['maxActiveEstimates'],
        'fileDefault' => $limits['fileDefault'],
        'overridden' => $limits['overridden'],
        'enabled' => $limits['enabled'],
        'appliesTo' => 'New estimates only - nothing already created is closed or deleted.'
    ];

    $warnings = [];
    // said plainly because 0 looks like the strictest possible setting and is in fact the loosest
    if (!$limits['enabled']) {
        $warnings[] = 'maxActiveEstimates is 0, which means NO cap - /estimates/create skips the count entirely. This is the shipped default, not an error. Set a positive number to start enforcing a limit.';
    }
    if ($limits['enabled'] && $limits['maxActiveEstimates'] <= 2) {
        $warnings[] = "A cap of {$limits['maxActiveEstimates']} is very low: an account holding that many open estimates cannot create another until one is marked Done. Check that is intended.";
    }
    if (!empty($warnings)) {
        $data['warnings'] = $warnings;
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Estimate limits updated: ' . $changed . '.',
        'data' => $data
    ], 200);
}

// GET /bookings/limits - the admin-control-panel twin of getEstimateLimits(), for a different axis
// entirely (see docs/proposals/server-side-booking-and-conflict-detection.md S7): this is how many
// overlapping bookings one CREW MEMBER may hold, not how many estimates one preparer owns.
function getBookingLimits(WP_REST_Request $request) {
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $limits = bookingLimitsConfig();

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'maxConcurrentBookings' => $limits['maxConcurrentBookings'],
            'fileDefault' => $limits['fileDefault'],
            'overridden' => $limits['overridden'],
            'enabled' => $limits['enabled'],
            'mode' => $limits['mode'],
            'modeFileDefault' => $limits['modeFileDefault'],
            'modeOverridden' => $limits['modeOverridden'],
            'counts' => 'Overlapping, not-Done estimates naming the same member in Member Trades, scoped to Local/Global separately (R7). A member with no window of their own never counts twice for the same day.',
            'appliesTo' => 'Checked on every /files/upload. mode=warn logs what WOULD be blocked without refusing anything - see the deploy log before flipping to block.'
        ]
    ], 200);
}

// GET /bookings/policy - the same booking limit, for a CLIENT rather than an operator.
//
// /bookings/limits already returns the number, but behind requireSessionKey, and the app cannot hold
// PROJEST_SESSION_KEY (docs/proposals/admin-role-verification-and-device-registration.md says so
// outright). So a client had no way to read the limit before hitting it - and since the check is still
// log-only, it could not even learn it from a 409. This is a separate endpoint rather than a second
// gate on the existing one, because the two answers are not the same shape: an operator needs to see
// how the server is configured, a client only needs the rule it has to obey.
//
// Deliberately NOT returned here: fileDefault, overridden, modeFileDefault, modeOverridden. Those say
// how this server was set up, which is operator business and no help to a client.
function getBookingPolicy(WP_REST_Request $request) {
    // Bearer is checked by the permission_callback; nothing else here is caller-specific. The limit is
    // per CREW MEMBER named in Member Trades, not per caller, so there is no "my" limit to scope to.
    unset($request);

    $limits = bookingLimitsConfig();

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'maxConcurrentBookings' => $limits['maxConcurrentBookings'],
            'enabled' => $limits['enabled'],
            'mode' => $limits['mode'],
            // Derived so a client does not have to know the mode vocabulary to answer the only
            // question it actually has: can an upload be refused because of this?
            'enforced' => $limits['mode'] === 'block',
            'appliesTo' => 'Overlapping, not-Done estimates naming the same member in Member Trades, scoped to Local/Global separately. Checked on every /files/upload.'
        ],
        'message' => $limits['enabled']
            ? 'Booking limit is ' . $limits['maxConcurrentBookings'] . ' per member (mode: ' . $limits['mode'] . ').'
            : 'No booking limit is set.'
    ], 200);
}

// GET /admin/teams/settings - the default window GET /teams/meetings uses when the caller sends
// no start/end, and whether an operator has moved it off the file default.
//
// Operator-gated rather than bearer-gated even though the number is harmless: it is a server
// control, and every other server control here sits behind the same key. The client-readable
// twin is the window echoed on /teams/meetings itself, which is what a client actually needs.
function getTeamsSettings(WP_REST_Request $request) {
    global $pe_msteams_organizer;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $range = msTeamsRangeConfig();
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'rangePastDays' => $range['rangePastDays'],
            'rangeFutureDays' => $range['rangeFutureDays'],
            'pastFileDefault' => $range['pastFileDefault'],
            'futureFileDefault' => $range['futureFileDefault'],
            'overridden' => $range['overridden'],
            // Not a secret and not settable here: it is a wp-config decision, and the Teams
            // application access policy has to name the same account. Reported so an operator
            // reading this page can see whether the deploy was ever told which mailbox to use.
            'organizerDefault' => (string) $pe_msteams_organizer,
            'organizerConfigured' => trim((string) $pe_msteams_organizer) !== '',
            'configured' => class_exists(MsGraphService::class)
        ]
    ], 200);
}

// PATCH /admin/teams/settings - retunes the default window without a redeploy. Same contract as
// PATCH /admin/bookings/limits: null reverts a key to the file default, and an empty option is
// deleted rather than stored, so "no override" is one state and not two.
function updateTeamsSettings(WP_REST_Request $request) {
    global $pe_msteams_settings_option, $pe_msteams_range_past_days, $pe_msteams_range_future_days;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    if (!$request->has_param('rangePastDays') && !$request->has_param('rangeFutureDays')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send rangePastDays and/or rangeFutureDays. A whole number of days, or null to revert to the file default.'
        ], 400);
    }

    $stored = get_option($pe_msteams_settings_option, []);
    $stored = is_array($stored) ? $stored : [];
    $changed = [];

    // 3660 days is ten years - not a policy, just far enough past any real question that a value
    // beyond it is a typo, and a typo here means a calendarView query that pages until it times out.
    foreach (['rangePastDays', 'rangeFutureDays'] as $key) {
        if (!$request->has_param($key)) {
            continue;
        }
        $value = $request->get_param($key);
        if ($value === null || $value === '') {
            unset($stored[$key]);
            $changed[] = $key . ' (reverted to file default)';
            continue;
        }
        if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $key . ' must be a non-negative whole number of days, or null to revert to the file default.'
            ], 400);
        }
        if ((int) $value > 3660) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $key . ' is capped at 3660 days (ten years) - a larger window pages until the request times out.'
            ], 400);
        }
        $stored[$key] = (int) $value;
        $changed[] = $key;
    }

    // Both zero would make the default window empty, which reads as "the integration is broken"
    // on every call that omits start and end.
    // Checked on the EFFECTIVE values, not the stored ones: a key absent from the option falls
    // back to the file default, so testing what was stored would miss "reverted one, zeroed the
    // other" if a file default were ever 0 itself.
    $pastPreview = $stored['rangePastDays'] ?? (int) $pe_msteams_range_past_days;
    $futurePreview = $stored['rangeFutureDays'] ?? (int) $pe_msteams_range_future_days;
    if ((int) $pastPreview === 0 && (int) $futurePreview === 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'rangePastDays and rangeFutureDays cannot both be 0 - the default window would be empty and every call without start/end would return nothing.'
        ], 400);
    }

    if (empty($stored)) {
        delete_option($pe_msteams_settings_option);
    } else {
        update_option($pe_msteams_settings_option, $stored, false);
    }

    $range = msTeamsRangeConfig();
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'changed' => $changed,
            'rangePastDays' => $range['rangePastDays'],
            'rangeFutureDays' => $range['rangeFutureDays'],
            'pastFileDefault' => $range['pastFileDefault'],
            'futureFileDefault' => $range['futureFileDefault'],
            'overridden' => $range['overridden']
        ],
        'message' => empty($changed) ? 'Nothing changed.' : implode(', ', $changed) . ' updated.'
    ], 200);
}

// PATCH /bookings/limits - retunes maxConcurrentBookings and/or mode without a redeploy, same shape as
// PATCH /estimates/limits and PATCH /ratelimits before it.
function updateBookingLimits(WP_REST_Request $request) {
    global $pe_booking_limits_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    if (!$request->has_param('maxConcurrentBookings') && !$request->has_param('mode')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send maxConcurrentBookings and/or mode. maxConcurrentBookings: 0 for no cap, null to revert. mode: off | warn | block, null to revert.'
        ], 400);
    }

    $stored = get_option($pe_booking_limits_option, []);
    $stored = is_array($stored) ? $stored : [];
    $changed = [];

    if ($request->has_param('maxConcurrentBookings')) {
        $value = $request->get_param('maxConcurrentBookings');
        if ($value === null || $value === '') {
            unset($stored['maxConcurrentBookings']);
            $changed[] = 'maxConcurrentBookings (reverted to file default)';
        } elseif (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'maxConcurrentBookings must be a non-negative integer (0 = no cap), or null to revert to the file default.'
            ], 400);
        } else {
            $stored['maxConcurrentBookings'] = (int) $value;
            $changed[] = 'maxConcurrentBookings';
        }
    }

    if ($request->has_param('mode')) {
        $mode = $request->get_param('mode');
        $allowedModes = ['off', 'warn', 'block'];
        if ($mode === null || $mode === '') {
            unset($stored['mode']);
            $changed[] = 'mode (reverted to file default)';
        } elseif (!in_array($mode, $allowedModes, true)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'mode must be one of: ' . implode(', ', $allowedModes) . ', or null to revert to the file default.'
            ], 400);
        } else {
            $stored['mode'] = $mode;
            $changed[] = 'mode';
        }
    }

    if (empty($stored)) {
        delete_option($pe_booking_limits_option);
    } else {
        update_option($pe_booking_limits_option, $stored, false);
    }

    $limits = bookingLimitsConfig();
    $data = [
        'changed' => $changed,
        'maxConcurrentBookings' => $limits['maxConcurrentBookings'],
        'fileDefault' => $limits['fileDefault'],
        'overridden' => $limits['overridden'],
        'enabled' => $limits['enabled'],
        'mode' => $limits['mode'],
        'modeFileDefault' => $limits['modeFileDefault'],
        'modeOverridden' => $limits['modeOverridden'],
        'appliesTo' => 'Checked on every /files/upload from now on. Nothing already saved is re-checked.'
    ];

    $warnings = [];
    if (!$limits['enabled']) {
        $warnings[] = 'maxConcurrentBookings is 0, which means NO cap - the booking check is skipped entirely regardless of mode.';
    }
    if ($limits['mode'] === 'block') {
        $warnings[] = 'mode is block: a save naming a member already at the limit is now refused with 409, not just logged.';
    }
    if (!empty($warnings)) {
        $data['warnings'] = $warnings;
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Booking limits updated: ' . implode(', ', $changed) . '.',
        'data' => $data
    ], 200);
}

function getMaintenance(WP_REST_Request $request) {
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => array_merge(maintenanceState(), ['exemptRoutes' => gateExemptRoutes()])
    ], 200);
}

// PATCH /maintenance - every toggle is independent, so the blast radius is composed rather than preset:
// all three on is a full window, blockReads+blockWrites keeps logins working, blockWrites alone is
// read-only. Only the keys present in the body are changed.
function updateMaintenance(WP_REST_Request $request) {
    global $pe_maintenance_option;
    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $state = maintenanceState();
    $booleans = ['enabled', 'blockAuth', 'blockReads', 'blockWrites'];
    $changed = [];

    foreach ($booleans as $key) {
        if (!$request->has_param($key)) {
            continue;
        }
        $state[$key] = filter_var($request->get_param($key), FILTER_VALIDATE_BOOLEAN);
        $changed[] = $key;
    }

    if ($request->has_param('message')) {
        $message = $request->get_param('message');
        if (!is_string($message)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'message must be a string.'
            ], 400);
        }
        $state['message'] = substr(sanitize_text_field($message), 0, 300);
        $changed[] = 'message';
    }

    if ($request->has_param('retryAfterSeconds')) {
        $retry = $request->get_param('retryAfterSeconds');
        if (!is_scalar($retry) || preg_match('/^\d+$/', (string) $retry) !== 1 || (int) $retry < 1) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'retryAfterSeconds must be a positive integer.'
            ], 400);
        }
        $state['retryAfterSeconds'] = (int) $retry;
        $changed[] = 'retryAfterSeconds';
    }

    if (empty($changed)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Send at least one of: enabled, blockAuth, blockReads, blockWrites, message, retryAfterSeconds.'
        ], 400);
    }

    // a window with every toggle off is on paper "enabled" but blocks nothing - say so rather than
    // letting an operator believe the API is down when every route is still answering
    $blocksNothing = $state['enabled'] && !$state['blockAuth'] && !$state['blockReads'] && !$state['blockWrites'];

    $state['updatedAt'] = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    // autoloaded on purpose: the gate reads this on every request in the namespace, so it should ride
    // along with the options WordPress already loads rather than costing its own query each time
    update_option($pe_maintenance_option, $state, true);

    return new WP_REST_Response([
        'success' => true,
        'message' => $blocksNothing
            ? 'Maintenance is enabled but no block toggle is on, so every route still answers.'
            : 'Maintenance settings updated.',
        'data' => array_merge($state, ['changed' => $changed, 'blocksNothing' => $blocksNothing])
    ], 200);
}

function getEstimateFiles(WP_REST_Request $request){
    $authUser = $request->get_param('authUser');
    $recordId = $request->get_param('recordId');

    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'recordId is required and must be a valid Airtable record id'
        ], 400);
    }

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $airtableHelper = new AirtableHelper();
    $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
    if (!$owned['ok']) {
        return $owned['response'];
    }

    $attachments = $owned['record']['fields']['Attachments'] ?? [];
    // this endpoint's rows are one record's files, so the limit trims that list instead of a record set
    if ($rowLimit['limit'] !== null) {
        $attachments = array_slice($attachments, 0, $rowLimit['limit']);
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => array_map(function($file) {
            return [
                'id' => $file['id'],
                'filename' => $file['filename'],
                'url' => $file['url'],
                'size' => $file['size'] ?? null,
                'type' => $file['type'] ?? null,
            ];
        }, $attachments),
    ], 200);
}

function downloadEstimateFile(WP_REST_Request $request){
    $authUser = $request->get_param('authUser');
    $recordId = $request->get_param('recordId');
    $attachmentId = $request->get_param('attachmentId');

    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'recordId is required and must be a valid Airtable record id'
        ], 400);
    }
    if (!isValidAirtableRecordId($attachmentId, 'att')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'attachmentId is required and must be a valid Airtable attachment id'
        ], 400);
    }

    $airtableHelper = new AirtableHelper();
    $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
    if (!$owned['ok']) {
        return $owned['response'];
    }

    $attachments = $owned['record']['fields']['Attachments'] ?? [];
    $attachment = null;
    foreach ($attachments as $file) {
        if ($file['id'] === $attachmentId) {
            $attachment = $file;
            break;
        }
    }

    if ($attachment === null) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Attachment not found on this record'
        ], 404);
    }

    // fetch the file server-side and stream it back ourselves, instead of handing back Airtable's
    // presigned url, so the download stays behind the ownership check above rather than the link alone
    $fetched = fetchAttachmentBytes($attachment['url']);
    if (!$fetched['ok']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch file from storage!',
            'error' => $fetched['error']
        ], 502);
    }
    $fileContents = $fetched['bytes'];

    // strip CR/LF/quotes before the filename reaches a header value - it's client-supplied at
    // upload time, so an unsanitized value could otherwise corrupt or inject response headers
    $safeFilename = preg_replace('/[\r\n"]/', '', $attachment['filename'] ?? 'download.json');
    if ($safeFilename === '') {
        $safeFilename = 'download.json';
    }

    // this bypasses the rest_pre_serve_request filter entirely (it exits before that filter would
    // run), so it needs its own no-cache headers rather than relying on the one set up top of this file
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: ' . ($attachment['type'] ?? 'application/json'));
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . strlen($fileContents));
    echo $fileContents;
    exit;
}

function uploadEstimateFile(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id;
    $authUser = $request->get_param('authUser');
    $payload = $request->get_param('payload'); // { recordId, file: { filename, contentType, base64 } }

    if (!is_array($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid payload'
        ], 400);
    }

    $recordId = $payload['recordId'] ?? null;
    $file = $payload['file'] ?? null;

    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.recordId is required and must be a valid Airtable record id'
        ], 400);
    }
    // require string filename/base64: a non-string (e.g. a JSON array) slips past empty() and would
    // fatal on base64_decode or reach Airtable, so reject it here as a clean 400
    if (!is_array($file) || !is_string($file['base64'] ?? null) || $file['base64'] === ''
        || !is_string($file['filename'] ?? null) || $file['filename'] === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.file (filename, contentType, base64) is required'
        ], 400);
    }

    // JSON only, and that is deliberate: what Airtable stores is the SERIALIZED ESTIMATE - the app's own
    // saved state, which it reloads and edits. The PDF report is rendered from this data client-side at
    // share time and is never stored here, so nothing about the PDF flow belongs in this endpoint.
    // Both the declared contentType and the decoded content are checked, since contentType is
    // client-supplied and renaming a file must not be enough to get it through.
    // Decode $decoded directly (no `?: ''`) so a valid-but-falsy body like "0" isn't misjudged.
    $contentType = $file['contentType'] ?? '';
    $decoded = base64_decode($file['base64'], true);
    // Captured, not discarded: the booking check below reads projectStart/projectEnd/memberTrades out of
    // this same decode rather than parsing the JSON a second time.
    $snapshot = null;
    if ($decoded !== false) {
        $snapshot = json_decode($decoded, true);
    }
    if ($contentType !== 'application/json' || $decoded === false || json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Only JSON files are accepted'
        ], 422);
    }

    // NO size cap here, deliberately. One was added on 2026-08-03 and removed the same day: it would have
    // rejected uploads this endpoint used to accept, which is a narrowing of a live contract. A serialized
    // estimate is a few KB so the cap solved a hypothetical, and the real consequence it guarded against -
    // a stored file too large for /estimates/share to email - is already reported there as a 413.
    // If a cap is ever wanted, it belongs on a new endpoint alongside this one, not bolted onto this one.

    $airtableHelper = new AirtableHelper();

    // ownership check also confirms the record exists (404) before we attempt the attachment upload
    $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
    if (!$owned['ok']) {
        return $owned['response'];
    }

    // Booking/conflict check - docs/proposals/server-side-booking-and-conflict-detection.md. These three
    // keys are the booking record per the source spec (SavedEstimate.projectStart/projectEnd/memberTrades)
    // and arrive on every real upload today, already inside $snapshot, unused until now.
    $projectStart = normalizeBookingDate(is_array($snapshot) ? ($snapshot['projectStart'] ?? null) : null);
    $projectEnd = normalizeBookingDate(is_array($snapshot) ? ($snapshot['projectEnd'] ?? null) : null);
    $memberTradesRaw = (is_array($snapshot) && is_array($snapshot['memberTrades'] ?? null)) ? $snapshot['memberTrades'] : [];
    $bookingScope = $owned['record']['fields']['Scope'] ?? 'Local';

    // Locked per member named in this upload (proposal S6.2), held around the ENTIRE check-then-write
    // sequence below, not just the check: releasing it between "passed the check" and "wrote Member Trades"
    // would reopen the exact race this exists to close, since a second request's check would still see the
    // column as it was before this one wrote it. An upload naming no members takes no lock at all.
    return withMemberLocks(array_keys($memberTradesRaw), function () use (
        $airtableHelper, $recordId, $file, $contentType, $projectStart, $projectEnd, $bookingScope, $memberTradesRaw,
        $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id
    ) {
        $bookingCheck = blockedMembersFor($airtableHelper, $projectStart, $projectEnd, $bookingScope, $recordId, array_keys($memberTradesRaw));
        if (!empty($bookingCheck['blocked'])) {
            $bookingMessage = count($bookingCheck['blocked']) . ' member(s) are already booked past the limit for this window.';
            if ($bookingCheck['mode'] === 'block') {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $bookingMessage,
                    'error' => 'booking_limit',
                    'data' => ['blocked' => $bookingCheck['blocked'], 'limit' => $bookingCheck['limit']]
                ], 409);
            }
            // warn: never refuses. Logged so a real deploy can observe how often this WOULD have blocked
            // before flipping $pe_booking_enforce_mode (or its /bookings/limits override) to 'block'.
            error_log("[ProjectEstimator] booking limit WOULD block (mode={$bookingCheck['mode']}) on {$recordId}: "
                . implode(', ', $bookingCheck['blocked']) . " (limit {$bookingCheck['limit']})");
        }

        $uploadResult = $airtableHelper->uploadAttachments($recordId, [
            'fields' => [
                $project_estimates_attachments_column_id => [[
                    'filename' => $file['filename'],
                    'contentType' => $contentType,
                    'base64Content' => $file['base64']
                ]]
            ]
        ], ['baseId' => $users_and_projects_baseId]);

        if (!$uploadResult['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to upload file!',
                'error' => $uploadResult['error']
            ], 502);
        }

        // This endpoint is how the app SAVES - what Airtable stores is the serialized estimate - so an
        // upload is the most significant modification a record gets, and it has to move Date Modified. It
        // costs a second request because attachments go through Airtable's own upload endpoint rather than
        // a field write.
        //
        // Best effort, and deliberately so: the file is already stored by this point. Failing the response
        // now would tell the client its save did not happen when it did, which is far worse than a
        // timestamp that lags until the next write. Also projects the booking fields this same upload just
        // carried (withBookingProjection()) - which is the actual reason this block runs inside the
        // per-member lock above; the field write itself still needs no lock of its own.
        $stamp = withModifiedStamp(withBookingProjection($airtableHelper, [], $projectStart, $projectEnd, $memberTradesRaw));
        if ($stamp !== []) {
            $stampResult = $airtableHelper->patchRecords([[
                'id' => $recordId,
                'fields' => $stamp
            ]], [
                'baseId' => $users_and_projects_baseId,
                'tableId' => $project_estimates_table_id
            ]);
            if (!$stampResult['success']) {
                error_log("[ProjectEstimator] file uploaded to {$recordId} but the Date Modified stamp failed: "
                    . ($stampResult['error'] ?? 'unknown error'));
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $uploadResult['result'],
            'message' => 'File uploaded!'
        ], 200);
    });
}

//----------------------BUG REPORTS-------------------------------
// Bug Reports table (tbltXTfNs9mtOuHIl, created 2026-08-11): Title, Description, Severity, Status,
// Reporter, Client Version, API Version, Device. Reporter holds the reporter's Accounts recordId, not a
// name or email - CLAUDE.md's no-personal-data constraint, and it means this table never becomes a second
// copy of anyone's contact details. An admin resolves it against Accounts as a deliberate second step.

// POST /bugs/report - any authenticated app user may file one. Filing is not an admin action; SEEING the
// list is (listBugReports below) - the two are gated differently on purpose.
function reportBug(WP_REST_Request $request) {
    global $users_and_projects_baseId, $project_estimator_bugreports_tableId;

    $authUser = $request->get_param('authUser');
    $payload = $request->get_param('payload');
    $callerId = is_array($authUser) ? ($authUser['recordId'] ?? null) : null;

    if (!isValidAirtableRecordId($callerId)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unable to identify caller'], 401);
    }
    if (!is_array($payload)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid payload'], 400);
    }

    $title = is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
    if ($title === '') {
        return new WP_REST_Response(['success' => false, 'message' => 'payload.title is required'], 400);
    }

    $allowedSeverities = ['Low', 'Medium', 'High', 'Critical'];
    $severity = $payload['severity'] ?? 'Medium';
    if (!in_array($severity, $allowedSeverities, true)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.severity must be one of: ' . implode(', ', $allowedSeverities)
        ], 400);
    }

    $fields = [
        'Title' => sanitize_text_field(substr($title, 0, 200)),
        'Description' => is_string($payload['description'] ?? null) ? sanitize_textarea_field($payload['description']) : '',
        'Severity' => $severity,
        'Status' => 'New', // caller cannot set Status at creation - triage is a read-side admin action
        'Reporter' => $callerId
    ];

    // optional diagnostic strings, filled from what the client already has at hand (its own version
    // headers, its own device label) rather than re-derived server-side - not identity-bearing, so plain
    // sanitization is enough
    foreach (['clientVersion' => 'Client Version', 'apiVersion' => 'API Version', 'device' => 'Device'] as $key => $column) {
        if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
            $fields[$column] = substr(sanitize_text_field($payload[$key]), 0, 100);
        }
    }

    $airtableHelper = new AirtableHelper();
    $result = $airtableHelper->uploadRecords([['fields' => $fields]], [
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimator_bugreports_tableId
    ]);

    if (!$result['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to file bug report!',
            'error' => $result['error']
        ], 502);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Bug report filed.',
        'data' => ['id' => $result['result'][0]['id'] ?? null]
    ], 200);
}

// GET /bugs/list - session-key gated. Bug reports are visible to whoever operates the server, not to the
// account that filed them or any other app user - "only admins can see those reports" (2026-08-11).
// Reporter is an Accounts recordId rather than a name, so resolving "who filed this" is a deliberate second
// lookup against Accounts, never a side effect of reading this list.
function listBugReports(WP_REST_Request $request) {
    global $users_and_projects_baseId, $project_estimator_bugreports_tableId;

    $gate = requireSessionKey($request);
    if (!$gate['ok']) {
        return $gate['response'];
    }

    $rowLimit = parseRowLimit($request);
    if (!$rowLimit['ok']) {
        return $rowLimit['response'];
    }

    $airtableHelper = new AirtableHelper();
    $result = $airtableHelper->fetchRecords([
        'baseId' => $users_and_projects_baseId,
        'tableId' => $project_estimator_bugreports_tableId
    ], null, ['Title', 'Description', 'Severity', 'Status', 'Reporter', 'Client Version', 'API Version', 'Device'], false, $rowLimit['limit']);

    if (!$result['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch bug reports!',
            'error' => $result['error']
        ], 502);
    }

    $data = array_map(function ($record) {
        return [
            'id' => $record['id'],
            'title' => $record['fields']['Title'] ?? '',
            'description' => $record['fields']['Description'] ?? '',
            'severity' => $record['fields']['Severity'] ?? null,
            'status' => $record['fields']['Status'] ?? null,
            'reporterAccountId' => $record['fields']['Reporter'] ?? null,
            'clientVersion' => $record['fields']['Client Version'] ?? null,
            'apiVersion' => $record['fields']['API Version'] ?? null,
            'device' => $record['fields']['Device'] ?? null,
            'createdTime' => $record['createdTime'] ?? null
        ];
    }, $result['result']);

    return new WP_REST_Response([
        'success' => true,
        'data' => $data
    ], 200);
}

function deleteEstimateFile(WP_REST_Request $request){
    global $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id;
    $authUser = $request->get_param('authUser');
    $payload = $request->get_param('payload'); // { recordId, attachmentId }

    if (!is_array($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid payload'
        ], 400);
    }

    $recordId = $payload['recordId'] ?? null;
    $attachmentId = $payload['attachmentId'] ?? null;

    if (!isValidAirtableRecordId($recordId)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.recordId is required and must be a valid Airtable record id'
        ], 400);
    }
    if (!isValidAirtableRecordId($attachmentId, 'att')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.attachmentId is required and must be a valid Airtable attachment id'
        ], 400);
    }

    $airtableHelper = new AirtableHelper();

    // Same lock key as /estimates/update, so an update and a file delete on one record serialise against
    // each other too - both end in a PATCH of the same record. Deleting an attachment means reading the
    // whole Attachments cell, removing one entry and writing the rest back, so two concurrent deletes
    // that interleave would each write a list still containing the other's file: one deletion silently
    // undone, reported as success. /files/upload needs no lock, since Airtable's uploadAttachment endpoint
    // appends server-side rather than replacing the cell.
    return withAdvisoryLock('estimate:' . $recordId, function () use ($airtableHelper, $recordId, $attachmentId, $authUser, $users_and_projects_baseId, $project_estimates_table_id, $project_estimates_attachments_column_id) {
        $owned = loadOwnedEstimate($airtableHelper, $recordId, $authUser);
        if (!$owned['ok']) {
            return $owned['response'];
        }

        $attachments = $owned['record']['fields']['Attachments'] ?? [];
        $remaining = array_values(array_filter($attachments, function($file) use ($attachmentId) {
            return $file['id'] !== $attachmentId;
        }));

        if (count($remaining) === count($attachments)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Attachment not found on this record'
            ], 404);
        }

        // PATCH replaces the whole field - existing attachments are kept by passing their objects back unchanged
        $patchResult = $airtableHelper->patchRecords([[
            'id' => $recordId,
            'fields' => withModifiedStamp([$project_estimates_attachments_column_id => $remaining])
        ]], [
            'baseId' => $users_and_projects_baseId,
            'tableId' => $project_estimates_table_id
        ]);

        if (!$patchResult['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to delete file!',
                'error' => $patchResult['error']
            ], 502);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $patchResult['result'],
            'message' => 'File deleted!'
        ], 200);
    });
}
//-----------------------------END: PROJECT ESTIMATOR FILES----------------------------------- keep

//----------------------START: ACCOUNT MANAGEMENT---------------------------------

// resolves the bearer token from the Authorization header, or null if absent/malformed
function getBearerToken(WP_REST_Request $request) {
	$authHeader = $request->get_header('authorization');
	if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
		return null;
	}
	return trim(substr($authHeader, 7));
}

// validates the request's bearer token against its stored session and attaches the resolved user.
// Every protected route's permission_callback funnels through here, which is why the IP lock lives here
// rather than in each handler - a new route cannot forget to enforce it.
function validateAuthToken(WP_REST_Request $request) {
	global $pe_auth_transient_prefix;
	$token = getBearerToken($request);
	if (!$token) {
		return false;
	}

	$session = get_transient($pe_auth_transient_prefix . $token);
	if (!$session) {
		return false;
	}

	// IP LOCK: the session dies rather than merely being refused, so a stolen token cannot be retried
	// from the original network later. Same 401 rest_forbidden the client already handles for expiry.
	$mode = sessionIpLockMode();
	if ($mode !== 'off') {
		$currentIp = getClientIp();
		$storedIp = is_array($session) ? ($session['ip'] ?? '') : '';
		if (!sessionIpAllowed($storedIp, $currentIp, $mode)) {
			delete_transient($pe_auth_transient_prefix . $token);
			sessionIndexForget($token, $session['recordId'] ?? '');
			// logged because this is the one failure mode users will report as "it keeps logging me out",
			// and without a trace there is no way to tell a real IP change from a stolen token
			error_log("[ProjectEstimator] session ended for {$session['username']}: IP changed from {$storedIp} to {$currentIp} (ipLock={$mode})");
			return false;
		}
	}

	$request->set_param('authUser', $session);
	return true;
}

// removes the Password field from returned Airtable record(s) so hashes never reach the client
function stripPasswordField($records) {
	if (!is_array($records)) {
		return $records;
	}
	foreach ($records as &$rec) {
		if (isset($rec['fields']['Password'])) {
			unset($rec['fields']['Password']);
		}
	}
	unset($rec);
	return $records;
}

// true if any record is a real account (has a non-empty Username); blank/autonumber-only rows do not count
function hasRealAccount(array $records) {
	foreach ($records as $record) {
		if (!empty($record['fields']['Username'])) {
			return true;
		}
	}
	return false;
}

// account-create gate: a logged-in user, OR bootstrap while no real account exists yet
function canCreateAccount(WP_REST_Request $request) {
	global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId, $pe_open_account_create;

	// staging toggle: open create with no auth when enabled (set false before production)
	if (!empty($pe_open_account_create)) {
		return true;
	}

	// 1) an existing logged-in user (admin creating accounts) - cheap check first
	if (validateAuthToken($request)) {
		return true;
	}

	// 2) bootstrap: allow while no real account exists yet (a blank row must not lock this)
	// Username ONLY. hasRealAccount() asks "does any real account exist yet", and answering a yes/no
	// question does not need anybody's password hash - this ran on the permission callback of every
	// create attempt and read all six columns of every row to do it. Row count and the scan are left
	// exactly as they were, so what counts as a real account is decided by the same code as before.
	$airtableHelper = new AirtableHelper();
	$fetchAccounts = $airtableHelper->fetchRecords([
		'baseId' => $project_estimator_accounts_baseId,
		'tableId' => $project_estimator_accounts_tableId
	], null, ['Username']);
	if ($fetchAccounts['success'] && !hasRealAccount($fetchAccounts['result'])) {
		return true;
	}

	return false;
}

// generates a permanent 8-char InviteCode, unique against the accounts already fetched (charset skips 0/O/1/I/L to avoid confusion)
function generateUniqueInviteCode(array $existingAccounts) {
	$charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
	$existingCodes = array_map(function($record) {
		return strtoupper(trim($record['fields']['InviteCode'] ?? ''));
	}, $existingAccounts);

	do {
		$code = '';
		for ($i = 0; $i < 8; $i++) {
			$code .= $charset[random_int(0, strlen($charset) - 1)];
		}
	} while (in_array($code, $existingCodes, true));

	return $code;
}

// formats the InvitedBy stamp as "Name::username" (distinct separator, safe to split on), falling back to just the username when Name is blank
function formatInvitedBy($name, $username) {
	$name = is_string($name) ? trim($name) : '';
	$username = is_string($username) ? $username : '';
	return $name === '' ? $username : $name . '::' . $username;
}

// client IP for rate limiting; deliberately ignores X-Forwarded-For since it is client-supplied
// and unverified on this host, so trusting it would let an attacker pick a fresh rate-limit bucket per request
function getClientIp() {
	return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Generic per-identifier rate limiter, namespaced by $bucket so no two endpoints share a counter.
//
// Every bucket declares its own budget AND its own window behaviour, because the two kinds of limit here
// want opposite things:
//   mode 'failures' -> SLIDING window. The TTL is re-stamped on every failure, so sustained guessing keeps
//                      the lockout alive. Correct for brute force: /auth/login, /account/redeem, operator key.
//   mode 'requests' -> FIXED window. The TTL is set once when the counter is created and never extended, so
//                      the budget actually resets on schedule. Correct for volume: emails sent, reads, writes.
// Using sliding for a volume budget is a trap - a caller at steady state re-stamps the window on every call
// and stays locked until they stop completely for the full window, which is not what "20 per hour" means.
function rateLimitDefaults() {
	global $pe_login_max_attempts, $pe_login_lockout_minutes, $pe_redeem_max_attempts, $pe_redeem_lockout_minutes,
		$pe_session_max_attempts, $pe_session_lockout_minutes, $pe_share_max_messages, $pe_share_window_minutes;

	return [
		// brute-force guards: the file globals stay the defaults so existing behaviour is unchanged
		'login'    => ['max' => (int) $pe_login_max_attempts,   'window' => (int) $pe_login_lockout_minutes,   'mode' => 'failures', 'scope' => 'ip'],
		'redeem'   => ['max' => (int) $pe_redeem_max_attempts,  'window' => (int) $pe_redeem_lockout_minutes,  'mode' => 'failures', 'scope' => 'ip'],
		'operator' => ['max' => (int) $pe_session_max_attempts, 'window' => (int) $pe_session_lockout_minutes, 'mode' => 'failures', 'scope' => 'ip'],
		// /account/modify's currentPassword check. Keyed on the TARGET account, not the caller's IP: access
		// is flat, so any token may aim at any account id, and a per-IP counter would be sidestepped by
		// rotating addresses. Scope 'target' means the identifier is supplied at the call site.
		// Accepted trade: 5 deliberate failures also block the real owner from changing their password for
		// the window. Self-healing, limited to that one endpoint, and the attacker needs a valid token anyway.
		'password' => ['max' => 5, 'window' => 15, 'mode' => 'failures', 'scope' => 'target'],
		// volume budgets, counted per account rather than per IP so one office behind one NAT is not one caller
		'share'    => ['max' => (int) $pe_share_max_messages,   'window' => (int) $pe_share_window_minutes,    'mode' => 'requests', 'scope' => 'account'],
		'read'     => ['max' => 0, 'window' => 5, 'mode' => 'requests', 'scope' => 'account'], // max 0 = off
		'write'    => ['max' => 0, 'window' => 5, 'mode' => 'requests', 'scope' => 'account'],
	];
}

// Resolves a bucket's settings: file defaults, with per-key operator overrides from the pe_rate_limits
// option layered on top. Overrides are per key, so raising one number does not require restating the rest.
// An unknown bucket falls back to a conservative failures-mode default rather than to "no limit".
function rateLimitConfig($bucket) {
	global $pe_rate_limits_option;
	$defaults = rateLimitDefaults();
	$config = $defaults[$bucket] ?? ['max' => 5, 'window' => 15, 'mode' => 'failures', 'scope' => 'ip'];

	$stored = get_option($pe_rate_limits_option, []);
	$override = (is_array($stored) && isset($stored[$bucket]) && is_array($stored[$bucket])) ? $stored[$bucket] : [];
	foreach (['max', 'window'] as $key) {
		if (isset($override[$key]) && (int) $override[$key] >= 0) {
			$config[$key] = (int) $override[$key];
		}
	}
	// mode and scope are structural, not tunables - letting an operator flip 'failures' to 'requests'
	// would silently change what the counter means without changing any number
	return $config;
}

// true when $identifier has used up $bucket's budget. $maxAttempts is accepted for call sites that still
// pass an explicit number, but omitting it is what lets the operator override apply.
function isRateLimited($bucket, $identifier, $maxAttempts = null) {
	global $pe_rate_limit_transient_prefix;
	$max = ($maxAttempts !== null) ? (int) $maxAttempts : (int) rateLimitConfig($bucket)['max'];
	if ($max <= 0) {
		return false; // 0 disables the bucket entirely
	}
	$entry = get_transient($pe_rate_limit_transient_prefix . $bucket . '_' . $identifier);
	return is_array($entry) && ($entry['count'] ?? 0) >= $max;
}

// Counts one hit against $bucket and returns the new count. Honours the bucket's window mode: see the
// note above rateLimitDefaults() for why fixed and sliding are not interchangeable.
function registerRateLimitHit($bucket, $identifier, $windowMinutes = null) {
	global $pe_rate_limit_transient_prefix;
	$config = rateLimitConfig($bucket);
	$window = ($windowMinutes !== null) ? (int) $windowMinutes : (int) $config['window'];
	$window = max(1, $window);
	$key = $pe_rate_limit_transient_prefix . $bucket . '_' . $identifier;

	$entry = get_transient($key);
	$count = (is_array($entry) ? (int) ($entry['count'] ?? 0) : 0) + 1;
	$now = time();

	if ($config['mode'] === 'requests' && is_array($entry) && !empty($entry['expiresAt'])) {
		// fixed window: keep the original deadline, so the TTL shrinks as the window runs out
		$ttl = max(1, (int) $entry['expiresAt'] - $now);
		$expiresAt = (int) $entry['expiresAt'];
	} else {
		// first hit of a fixed window, or any hit of a sliding one
		$ttl = $window * MINUTE_IN_SECONDS;
		$expiresAt = $now + $ttl;
	}

	set_transient($key, ['count' => $count, 'expiresAt' => $expiresAt], $ttl);
	return $count;
}

// Kept as the name four handlers already call, and because "failure" is the accurate word at those sites.
function registerRateLimitFailure($bucket, $identifier, $lockoutMinutes = null) {
	return registerRateLimitHit($bucket, $identifier, $lockoutMinutes);
}

// seconds until $bucket's counter for $identifier expires, for the Retry-After header; 0 when not counting
function rateLimitRetryAfter($bucket, $identifier) {
	global $pe_rate_limit_transient_prefix;
	$entry = get_transient($pe_rate_limit_transient_prefix . $bucket . '_' . $identifier);
	if (!is_array($entry) || empty($entry['expiresAt'])) {
		return 0;
	}
	return max(0, (int) $entry['expiresAt'] - time());
}

// clears the counter for $identifier within $bucket (call after a genuine success)
function clearRateLimitFailures($bucket, $identifier) {
	global $pe_rate_limit_transient_prefix;
	delete_transient($pe_rate_limit_transient_prefix . $bucket . '_' . $identifier);
}

// Who a limit is counted against. 'account' keys off the authenticated user so colleagues behind one
// office IP are not treated as one caller, and falls back to IP when there is no session to key on.
function rateLimitIdentifier($bucket, WP_REST_Request $request) {
	if (rateLimitConfig($bucket)['scope'] !== 'account') {
		return getClientIp();
	}

	$authUser = $request->get_param('authUser');
	$recordId = is_array($authUser) ? ($authUser['recordId'] ?? '') : '';

	// authUser is only attached once permission_callback has run, so anything earlier (the gate filter)
	// has to resolve the session from the token itself - otherwise every authenticated caller would
	// silently collapse into one per-IP bucket, which is the opposite of what 'account' scope means
	if ($recordId === '') {
		global $pe_auth_transient_prefix;
		$token = getBearerToken($request);
		$session = $token ? get_transient($pe_auth_transient_prefix . $token) : false;
		$recordId = is_array($session) ? ($session['recordId'] ?? '') : '';
	}

	return $recordId !== '' ? 'acct_' . $recordId : getClientIp();
}

// Which throttle bucket a gated request counts against: null means "not throttled here". Auth routes are
// excluded because their handlers run their own 'login'/'redeem' buckets, and double-counting them would
// let a read budget lock someone out of logging in.
function gateRateBucket($path, $method) {
	if (in_array($path, ['/auth/login', '/account/redeem', '/account/create'], true)) {
		return null;
	}
	return $method === 'GET' ? 'read' : 'write';
}

// Builds the 429 for a bucket, including how long to wait. Shared so every throttled endpoint reports
// the same shape instead of each inventing its own.
function rateLimitedResponse($bucket, $identifier, $message) {
	$retryAfter = rateLimitRetryAfter($bucket, $identifier);
	$response = new WP_REST_Response([
		'success' => false,
		'message' => $message,
		'error' => 'rate_limited',
		'data' => ['bucket' => $bucket, 'retryAfterSeconds' => $retryAfter]
	], 429);
	if ($retryAfter > 0) {
		$response->header('Retry-After', (string) $retryAfter);
	}
	return $response;
}

// public: the invitee submits a scanned InviteCode (permanent, belongs to an existing account) plus their own chosen credentials
function redeemAccountInvite(WP_REST_Request $request){
	global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;
	$inviteCode = $request->get_param('inviteCode');
	$payload = $request->get_param('payload'); // the invitee's own account data { fields: { Name, Username, Password } }

	if (!is_string($inviteCode) || trim($inviteCode) === '') {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'inviteCode is required!'
		], 400);
	}
	$inviteCode = strtoupper(trim($inviteCode));

	$username = $payload['fields']['Username'] ?? null;
	$rawPassword = $payload['fields']['Password'] ?? null;
	if (!is_string($username) || $username === '' || !is_string($rawPassword) || $rawPassword === '') {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Username and password are required!'
		], 400);
	}

	$ip = getClientIp();
	// no explicit max/window passed here or below: the limiter resolves the bucket's config itself, so an
	// operator override via PATCH /ratelimits actually applies instead of being overridden by the global
	if (isRateLimited('redeem', $ip)) {
		return rateLimitedResponse('redeem', $ip, 'Too many failed invite code attempts. Try again later.');
	}

	$airtableHelper = new AirtableHelper();
	// Every row, because generateUniqueInviteCode() below has to check the new code against every code
	// that already exists - but three columns rather than six. The Password of every account on the
	// system was being read to redeem one invite.
	$fetchAccounts = $airtableHelper->fetchRecords([
		'baseId' => $project_estimator_accounts_baseId,
		'tableId' => $project_estimator_accounts_tableId
	], null, ['Username', 'InviteCode', 'Name']);
	if (!$fetchAccounts['success']) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Failed to verify invite code!',
			'error' => $fetchAccounts['error']
		], 502);
	}

	// the InviteCode belongs to an existing account and never expires or gets consumed
	$inviter = null;
	foreach ($fetchAccounts['result'] as $record) {
		// only a real account (non-empty Username) can act as inviter; blank rows cannot carry a usable code
		if (!empty($record['fields']['Username']) && strcasecmp(trim($record['fields']['InviteCode'] ?? ''), $inviteCode) === 0) {
			$inviter = $record;
			break;
		}
	}
	if (!$inviter) {
		$attempts = registerRateLimitFailure('redeem', $ip);
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Invalid invite code!',
			'attemptsLeft' => max(0, (int) rateLimitConfig('redeem')['max'] - $attempts),
		], 401);
	}

	// a genuine invite code clears this IP's failure count, regardless of what happens next (e.g. a taken username)
	clearRateLimitFailures('redeem', $ip);

	foreach ($fetchAccounts['result'] as $record) {
		if (strcasecmp($record['fields']['Username'] ?? '', $username) === 0) {
			// 409: the request is well-formed, it just conflicts with an existing Username
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Username already exists!'
			], 409);
		}
	}

	$uploadResult = $airtableHelper->uploadRecords([[
		'fields' => [
			'Name'       => $payload['fields']['Name'] ?? '',
			'Username'   => $username,
			'Password'   => password_hash($rawPassword, PASSWORD_DEFAULT),
			'InviteCode' => generateUniqueInviteCode($fetchAccounts['result']), // every new account gets its own permanent code
			'InvitedBy'  => formatInvitedBy($inviter['fields']['Name'] ?? '', $inviter['fields']['Username'] ?? ''), // records whose code was redeemed as "Name::username"
		]
	]], [
		'baseId' => $project_estimator_accounts_baseId,
		'tableId' => $project_estimator_accounts_tableId
	]);

	if (!$uploadResult['success']) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Failed to create user account!',
			'error' => $uploadResult['error']
		], 502);
	}

	return new WP_REST_Response([
		'success' => true,
		'data' => stripPasswordField($uploadResult['result']),
		'message' => 'Account created! You can now log in.'
	], 200);
}

function loginUser(WP_REST_Request $request){
    // $pe_auth_token_ttl_seconds is not pulled in here on purpose - sessionTtlSeconds() reads it, so that
    // an operator-set TTL cannot be bypassed by this handler reading the file default directly
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId, $pe_auth_transient_prefix;
    $username = $request->get_param('username');
    $password = $request->get_param('password');

    // this route is public; non-string params would fatal strcasecmp/password_verify
    if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Username and password are required!'
        ], 400);
    }

    $ip = getClientIp();
    if (isRateLimited('login', $ip)) {
        return rateLimitedResponse('login', $ip, 'Too many failed login attempts. Try again later.');
    }

    $airtableHelper = new AirtableHelper();
    // ONE row, and four columns of it. Every login used to read the whole table - every account's
    // password hash - into memory to find one person and check one hash.
    //
    // LOWER() reproduces the strcasecmp() below, which is kept and is still what decides a match: this
    // filter only narrows what is fetched, so a row that comes back is accepted or rejected by exactly
    // the comparison it always was. strtolower() rather than mb_strtolower() on purpose - strcasecmp()
    // is ASCII-only too, so the two agree; a username with non-ASCII case would be the one shape where
    // Airtable's Unicode LOWER() and this could disagree.
    $fetchAccounts = $airtableHelper->fetchRecords([
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ], 'LOWER({Username}) = ' . airtableFormulaStringLiteral(strtolower($username)),
        ['Username', 'Password', 'Name', 'InviteCode']);

    if (!$fetchAccounts['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to verify credentials!',
            'error' => $fetchAccounts['error']
        ], 502);
    }

    $account = null;
    foreach ($fetchAccounts['result'] as $record) {
        if (strcasecmp($record['fields']['Username'] ?? '', $username) === 0) {
            $account = $record;
            break;
        }
    }

    if (!$account || !password_verify($password, $account['fields']['Password'] ?? '')) {
        $attempts = registerRateLimitFailure('login', $ip);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid username or password!',
            'attemptsLeft' => max(0, (int) rateLimitConfig('login')['max'] - $attempts),
        ], 401);
    }

    // Past the guard above, $account is always a record - but the analyser does not narrow through it and
    // reports "use of unassigned variable" on the three bare $account['id'] reads below. The neighbouring
    // $account['fields'][...] reads are quiet only because ?? suppresses the same warning.
    //
    // Annotated rather than restructured, and that is the point: the obvious way to satisfy the analyser
    // is to split the guard into "if (!$account)" and "if (!password_verify(...))". Those two must answer
    // IDENTICALLY - one 401, one message, one attempt counter - or the difference between them tells an
    // anonymous caller which usernames exist. The combined condition is what makes that impossible to get
    // wrong later.
    /** @var array $account */

    // a genuine login clears this IP's failure count
    clearRateLimitFailures('login', $ip);

    // backfill a permanent InviteCode for accounts created before this field existed
    $inviteCode = trim($account['fields']['InviteCode'] ?? '');
    if ($inviteCode === '') {
        // A SECOND read, and it has to be: the fetch above is now one row, and a code checked for
        // uniqueness against one row is not unique. One column, and only on this path - an account
        // created before InviteCode existed, which is a handful of logins ever, not every login.
        $existingCodes = $airtableHelper->fetchRecords([
            'baseId' => $project_estimator_accounts_baseId,
            'tableId' => $project_estimator_accounts_tableId
        ], null, ['InviteCode']);
        $inviteCode = generateUniqueInviteCode($existingCodes['success'] ? $existingCodes['result'] : []);
        // best-effort: a failed patch here just means the code is regenerated on the next login
        $airtableHelper->patchRecords([[
            'id' => $account['id'],
            'fields' => ['InviteCode' => $inviteCode]
        ]], [
            'baseId' => $project_estimator_accounts_baseId,
            'tableId' => $project_estimator_accounts_tableId
        ]);
    }

    $token = bin2hex(random_bytes(32));
    $ttlSeconds = sessionTtlSeconds(); // operator override wins over the $pe_auth_token_ttl_seconds default
    // one timestamp for the transient, the index and the response, so all three agree to the second
    $loginAt = time();
    $expiresAt = $loginAt + $ttlSeconds;
    set_transient($pe_auth_transient_prefix . $token, [
        'recordId' => $account['id'],
        'name' => $account['fields']['Name'] ?? '',
        'username' => $account['fields']['Username'] ?? '',
        'inviteCode' => $inviteCode,
        // the address this token was issued to; validateAuthToken compares every later request against it
        // when the IP lock is on. Kept in the transient rather than read from the session index so the
        // check costs nothing extra on the hot path.
        'ip' => $ip,
        // stored so /currentUser can report this token's own lifetime: a transient cannot be asked how long
        // it has left, and reading the session index instead would cost an extra option read on every call
        'ttlSeconds' => $ttlSeconds,
        'loginAt' => $loginAt,
        'expiresAt' => $expiresAt,
    ], $ttlSeconds);

    // the only record that this login happened - the session transient cannot be enumerated, so without
    // this entry /session/list could never report it. Best-effort: a failed write costs visibility, not the login.
    // Optional device identity from the client. IP and User-Agent cannot group logins into devices - a
    // phone's IP changes on every network hop and its UA is identical across every install of the same
    // build - so the app generates a UUID once, stores it, and sends it here. A repeat login from a known
    // deviceId replaces that device's entry rather than adding another (see sessionIndexAdd).
    //
    // NOT a security boundary: it is client-supplied, so it can be spoofed, copied or cleared. It is a
    // grouping label to make a device list legible. Authorization stays on the server-side recordId.
    $device = parseDeviceIdentity($request);

    sessionIndexAdd($token, [
        // recordId is the canonical identity everywhere else (ownership markers, authUser), so the index
        // records it too - it is the row key, and what lets a password change end this account's sessions
        'recordId' => $account['id'],
        'name' => $account['fields']['Name'] ?? '',
        'username' => $account['fields']['Username'] ?? '',
        'ip' => $ip,
        'device' => clientDeviceLabel(),
        'deviceId' => $device['deviceId'],
        'deviceName' => $device['deviceName'],
        'loginAt' => $loginAt,
        'expiresAt' => $expiresAt
    ]);

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'token' => $token,
            'name' => $account['fields']['Name'] ?? '',
            'username' => $account['fields']['Username'] ?? '',
            'inviteCode' => $inviteCode,
            // None of this is inferable from the token, which is 64 random hex characters carrying no claims.
            // Without it the only signal a session has ended is a 401 on whatever the user happened to do
            // next. Both epoch and UTC string are given so a client need not parse or format either.
            'ttlSeconds' => $ttlSeconds,
            'ttlHuman' => formatTtlSeconds($ttlSeconds),
            // DEPRECATED, kept so an existing reader still gets a number. Null when the TTL is not a whole
            // number of minutes, because reporting 90 seconds as "1 minute" would just be wrong.
            'ttlMinutes' => ($ttlSeconds % 60 === 0) ? intdiv($ttlSeconds, 60) : null,
            'loginAt' => $loginAt,
            'loginAtUtc' => gmdate('Y-m-d H:i:s', $loginAt),
            'expiresAt' => $expiresAt,
            'expiresAtUtc' => gmdate('Y-m-d H:i:s', $expiresAt),
            // server-computed, so a device with a skewed clock still counts down correctly
            'expiresInSeconds' => $expiresAt - $loginAt,
        ],
        'message' => 'Login successful!'
    ], 200);
}

function logoutUser(WP_REST_Request $request){
    global $pe_auth_transient_prefix;
    $token = getBearerToken($request);
    if ($token) {
        // read the session BEFORE deleting it - its recordId is what locates the index row to clean up,
        // and without it sessionIndexForget falls back to scanning every account's row
        $session = get_transient($pe_auth_transient_prefix . $token);
        delete_transient($pe_auth_transient_prefix . $token);
        sessionIndexForget($token, is_array($session) ? ($session['recordId'] ?? '') : '');
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Logged out successfully!'
    ], 200);
}

function getCurrentUser(WP_REST_Request $request){
    $authUser = $request->get_param('authUser');

    // is_array as well as empty: the old body returned $authUser verbatim and survived a malformed session,
    // but array_merge below would fatal on one. Same guard validateAuthToken already applies at its IP check.
    if (empty($authUser) || !is_array($authUser)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No active session found!'
        ], 401);
    }

    // The token's own lifetime, so a client can show "signed in until ..." and refresh before it dies rather
    // than discovering it through a 401. Only /auth/login can know these, so they are read back from the
    // session it stored. A token issued before those keys existed reports null - not 0, which a client would
    // reasonably read as "expired right now" and sign the user out of a session that is still perfectly good.
    // Re-logging in fills them in, and at the current TTL every such token is gone within a day anyway.
    // ttlSeconds since 2026-08-05; a session minted in the three days before that stored ttlMinutes instead,
    // so it is converted rather than reported as unknown
    $ttlSeconds = (int) ($authUser['ttlSeconds'] ?? 0);
    if ($ttlSeconds <= 0 && isset($authUser['ttlMinutes'])) {
        $ttlSeconds = (int) $authUser['ttlMinutes'] * 60;
    }
    $loginAt = (int) ($authUser['loginAt'] ?? 0);
    $expiresAt = (int) ($authUser['expiresAt'] ?? 0);

    $data = array_merge($authUser, [
        'ttlSeconds' => $ttlSeconds > 0 ? $ttlSeconds : null,
        'ttlHuman' => $ttlSeconds > 0 ? formatTtlSeconds($ttlSeconds) : null,
        // DEPRECATED; null both when unknown and when not a whole number of minutes
        'ttlMinutes' => ($ttlSeconds > 0 && $ttlSeconds % 60 === 0) ? intdiv($ttlSeconds, 60) : null,
        'loginAt' => $loginAt > 0 ? $loginAt : null,
        'loginAtUtc' => $loginAt > 0 ? gmdate('Y-m-d H:i:s', $loginAt) : null,
        'expiresAt' => $expiresAt > 0 ? $expiresAt : null,
        'expiresAtUtc' => $expiresAt > 0 ? gmdate('Y-m-d H:i:s', $expiresAt) : null,
        // computed now rather than at login, so it is the time actually remaining
        'expiresInSeconds' => $expiresAt > 0 ? max(0, $expiresAt - time()) : null,
    ]);

    return new WP_REST_Response([
        'success' => true,
        'data' => $data
    ], 200);
}

function addUserAccount(WP_REST_Request $request){
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;
    $payload = $request->get_param('payload'); // the account data to upload
    $debug = filter_var($request->get_param('debug'), FILTER_VALIDATE_BOOLEAN); // testing: dry-run diagnostics
    $writeParam = $request->get_param('write');
    $write = ($writeParam === null) ? true : filter_var($writeParam, FILTER_VALIDATE_BOOLEAN); // default true; false skips the write to the shared staging table
    $airtableHelper = new AirtableHelper();

    $username = $payload['fields']['Username'] ?? null;
    $rawPassword = $payload['fields']['Password'] ?? null;
    // require non-empty strings; arrays/other types would fatal password_hash/strcasecmp
    if (!is_string($username) || $username === '' || !is_string($rawPassword) || $rawPassword === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Username and password are required!'
        ], 400);
    }

    //check for duplicate usernames
    // Every row for the same reason as redeemAccountInvite - generateUniqueInviteCode() below needs
    // every existing code - but two columns. Creating an account was reading every other account's
    // password hash and name to check one username was free.
    $fetchExisting = $airtableHelper->fetchRecords([
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ], null, ['Username', 'InviteCode']);

    if (!$fetchExisting['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to fetch existing accounts!',
            'error' => $fetchExisting['error']
        ], 502);
    }

    foreach ($fetchExisting['result'] as $record) {
        if (strcasecmp($record['fields']['Username'] ?? '', $username) === 0) {
            // 409: the request is well-formed, it just conflicts with an existing Username
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Username already exists!'
            ], 409);
        }
    }

    // the session must come from token validation only - a client-sent authUser param is never trusted (canCreateAccount skips the token check in open mode)
    $authUser = validateAuthToken($request) ? $request->get_param('authUser') : null;
    $invitedBy = is_array($authUser) ? formatInvitedBy($authUser['name'] ?? '', $authUser['username'] ?? '') : ''; // records the logged-in creator as "Name::username"; blank for session-less bootstrap/open creates

    // dry run: debug always skips the write; write=false explicitly disables it (accounts table is shared staging)
    if ($debug || !$write) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Dry run - account was NOT written',
            'data' => [
                'wouldCreate'   => ['Name' => $payload['fields']['Name'] ?? null, 'Username' => $username, 'InvitedBy' => $invitedBy],
                'existingCount' => count($fetchExisting['result']),
                'write'         => $write,
                'debug'         => $debug,
            ],
        ], 200);
    }

    $payload['fields']['Password'] = password_hash($rawPassword, PASSWORD_DEFAULT);
    $payload['fields']['InviteCode'] = generateUniqueInviteCode($fetchExisting['result']); // every account gets a permanent invite code; overrides any client-sent value
    $payload['fields']['InvitedBy'] = $invitedBy; // overrides any client-sent value

    // uploadRecords expects a list of records
    $uploadResult = $airtableHelper->uploadRecords([$payload], [
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ]);

    //error handling
    if(!$uploadResult['success']){
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to create user account!',
            'error' => $uploadResult['error']
        ], 502);
    }
    else{
        return new WP_REST_Response([
            'success' => true,
            'data' => stripPasswordField($uploadResult['result']),
            'message' => 'User account successfully created!'
        ], 200);
    }
}

// Shared implementation behind PATCH /account/modify (deprecated) and POST /account/password (current).
// Two endpoints rather than one changed in place, because signing out the account's other sessions alters
// what a SUCCESSFUL call does - an existing caller must not inherit that silently.
//
// $options:
//   'strictId'         - require payload.id to be a well-formed Accounts record id. Only the new endpoint
//                        does this; the old one kept its looser check so its 400/404 behaviour is unchanged.
//   'endOtherSessions' - end the account's other sessions after a successful change. The reason the new
//                        endpoint exists.
//   'supersededBy'     - path to advertise in the response when this endpoint is deprecated.
//
// The rate limit is applied by BOTH, deliberately. It is a security control, not a behaviour change: the
// success path is untouched and a legitimate caller never reaches it. Both share one counter per target
// account, so an attacker cannot get five more guesses by switching endpoint.
function runPasswordChange(WP_REST_Request $request, array $options) {
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;
    $payload = $request->get_param('payload'); // { id, currentPassword, fields: { Password } }
    $airtableHelper = new AirtableHelper();

    if (!is_array($payload)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid payload'
        ], 400);
    }

    $id = $payload['id'] ?? null;
    $currentPassword = $payload['currentPassword'] ?? null;
    $fields = $payload['fields'] ?? null;

    // The old endpoint keeps its looser check so its status codes are unchanged; the new one is strict.
    // Either way $id never reaches a key unbounded - the rate-limit bucket below hashes it.
    if (!empty($options['strictId'])) {
        if (!isValidAirtableRecordId($id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'payload.id is required and must be a valid Accounts record id'
            ], 400);
        }
    } elseif (!is_string($id) || $id === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.id is required'
        ], 400);
    }
    if (!is_string($currentPassword) || $currentPassword === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.currentPassword is required'
        ], 400);
    }
    if (!is_array($fields) || !is_string($fields['Password'] ?? null) || $fields['Password'] === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload.fields.Password is required'
        ], 400);
    }

    // this endpoint only ever changes Password; Name/Username/InviteCode/anything else is rejected
    $extraFields = array_diff_key($fields, ['Password' => null]);
    if (!empty($extraFields)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'This endpoint can only change Password; remove: ' . implode(', ', array_keys($extraFields))
        ], 422);
    }

    // Throttle the currentPassword check, keyed on the account BEING CHANGED rather than the caller's IP.
    // Access is flat (any token may target any id) and this handler is the only place a password can be
    // guessed at without limit, so before this it was an unmetered brute force against a chosen account.
    // Checked before the Airtable fetch so a locked-out caller costs nothing upstream.
    // hashed so an arbitrary payload.id cannot create unbounded transient keys, and so both endpoints
    // derive the SAME bucket for the same account - one counter, not one per endpoint
    $passwordBucket = 'acct_' . substr(hash('sha256', (string) $id), 0, 16);
    if (isRateLimited('password', $passwordBucket)) {
        return rateLimitedResponse('password', $passwordBucket, 'Too many failed attempts for this account. Try again later.');
    }

    // ONE row, by the id this call already names, and one column of it. Checking one password used to
    // read every password on the system.
    //
    // Escaped through airtableFormulaStringLiteral() rather than interpolated: $id reaches here as a
    // non-empty string on one branch WITHOUT having passed isValidAirtableRecordId(), and a raw quote in
    // a formula is a broken query at best. The id scan below is kept and still decides the match.
    $fetchAccounts = $airtableHelper->fetchRecords([
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ], 'RECORD_ID() = ' . airtableFormulaStringLiteral($id), ['Password']);
    if (!$fetchAccounts['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to verify current password!',
            'error' => $fetchAccounts['error']
        ], 502);
    }

    $account = null;
    foreach ($fetchAccounts['result'] as $record) {
        if ($record['id'] === $id) {
            $account = $record;
            break;
        }
    }
    if (!$account) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No account found with that id'
        ], 404);
    }

    if (!password_verify($currentPassword, $account['fields']['Password'] ?? '')) {
        $attempts = registerRateLimitFailure('password', $passwordBucket);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Current password is incorrect',
            'attemptsLeft' => max(0, (int) rateLimitConfig('password')['max'] - $attempts)
        ], 401);
    }

    // proof of the current password clears the counter, so a legitimate owner is never locked out by
    // someone else's failed guesses for longer than it takes them to get one right
    clearRateLimitFailures('password', $passwordBucket);

    $patchResult = $airtableHelper->patchRecords([[
        'id' => $id,
        'fields' => ['Password' => password_hash($fields['Password'], PASSWORD_DEFAULT)]
    ]], [
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ]);

    if (!$patchResult['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to update password!',
            'error' => $patchResult['error']
        ], 502);
    }

    // data stays the record list it has always been (ADR 0010 point 7); anything new is a sibling key,
    // the same way loginUser and redeemAccountInvite return attemptsLeft
    $response = [
        'success' => true,
        'data' => stripPasswordField($patchResult['result']),
        'message' => 'Password successfully updated!'
    ];

    // Only the current endpoint does this. Every token minted under the OLD password stops working, which
    // is what makes changing a leaked password mean something - before, the token outlived the change.
    //
    // The caller's own session is spared, so changing your own password does not sign you out of the device
    // you did it on. Note the consequence: if the caller IS the attacker (they knew the current password),
    // sparing them keeps them in - but they could log straight back in with the new password anyway, so
    // this costs nothing real.
    if (!empty($options['endOtherSessions'])) {
        $endedSessions = terminateSessionsForAccount($id, (string) getBearerToken($request));
        $response['endedSessions'] = $endedSessions;
        if ($endedSessions > 0) {
            $response['message'] = "Password successfully updated! {$endedSessions} other session(s) were signed out.";
        }
    }

    if (!empty($options['supersededBy'])) {
        $response['deprecated'] = "This endpoint is deprecated - use {$options['supersededBy']}, which also signs out the account's other sessions. Both still work.";
    }

    return new WP_REST_Response($response, 200);
}

// PATCH /account/modify - DEPRECATED. Kept byte-for-byte behaviour-compatible on its success path so the
// existing frontend is unaffected; only the rate limit (a security control that a legitimate caller never
// reaches) was added. Migrate to POST /account/password, then remove this.
function changeUserPassword(WP_REST_Request $request) {
    return runPasswordChange($request, [
        'strictId' => false,
        'endOtherSessions' => false,
        'supersededBy' => 'POST /account/password'
    ]);
}

// POST /account/password - the current password-change endpoint. Same request shape as /account/modify,
// plus: strict payload.id validation, and the account's other sessions are signed out on success.
function changeAccountPassword(WP_REST_Request $request) {
    return runPasswordChange($request, [
        'strictId' => true,
        'endOtherSessions' => true
    ]);
}

function deleteUserAccount(WP_REST_Request $request){
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;
    $payload = $request->get_param('payload'); // record id string, or a list of id strings
    $airtableHelper = new AirtableHelper();

    // normalize a single id to a list; deleteRecords expects a list of id strings
    if (is_string($payload) && $payload !== '') {
        $payload = [$payload];
    }
    if (!is_array($payload) || array_filter($payload, 'is_string') !== $payload || $payload === []) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'payload must be a record id or a list of record ids'
        ], 400);
    }

    $deleteResult = $airtableHelper->deleteRecords($payload, [
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ]);

    //error handling
    if(!$deleteResult['success']){
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to delete user account!',
            'error' => $deleteResult['error']
        ], 502);
    }
    else{
        return new WP_REST_Response([
            'success' => true,
            'data' => $deleteResult['result'],
            'message' => 'User account successfully deleted!'
        ], 200);
    }
}

// TEMPORARY DEBUG - confirms the server can read the Project Estimator accounts table; comment out after diagnosing
function debugReadAccounts(WP_REST_Request $request){
    global $project_estimator_accounts_baseId, $project_estimator_accounts_tableId;
    $airtableHelper = new AirtableHelper();

    $fetch = $airtableHelper->fetchRecords([
        'baseId' => $project_estimator_accounts_baseId,
        'tableId' => $project_estimator_accounts_tableId
    ]);

    if (!$fetch['success']) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Server could NOT read the accounts table',
            'error'   => $fetch['error'],
            'baseId'  => $project_estimator_accounts_baseId,
            'tableId' => $project_estimator_accounts_tableId,
        ], 502);
    }

    return new WP_REST_Response([
        'success'       => true,
        'message'       => 'Server CAN read the accounts table',
        'accountCount'  => count($fetch['result']),
        'bootstrapOpen' => !hasRealAccount($fetch['result']), // true means /account/create works with no token/invite
        'records'       => stripPasswordField($fetch['result']), // Password stripped for safety
    ], 200);
}

//----------------------END: ACCOUNT MANAGEMENT------------------------------------

//----------------------MICROSOFT TEAMS-------------------------------------------

// The organizer for this request: an explicit ?organizer= wins over the file default, and '' means
// neither was given. Deliberately NOT defaulted to the caller's own account - the Teams application
// access policy is granted per account, so a silent fallback would 403 from Graph and read as a broken
// integration rather than as a missing parameter.
function msTeamsOrganizer(WP_REST_Request $request) {
    global $pe_msteams_organizer;
    $supplied = $request->get_param('organizer');
    if (is_string($supplied) && trim($supplied) !== '') {
        return trim($supplied);
    }
    return trim((string) $pe_msteams_organizer);
}

// Builds the Graph client, or the 503 that says the integration was never configured - the same
// fail-closed answer the operator endpoints give for an undefined PROJEST_SESSION_KEY. Only the
// CONSTRUCTOR can fail this way, since it is what reads the wp-config constants, which is why building
// the client is its own step: everything after it is an upstream failure and gets 502 instead.
// The missing constant's name is not returned - the caller cannot act on it, and it describes the
// server's configuration rather than their request.
// Shared 503, so the two ways the integration can be absent cannot answer differently. Both are
// "nobody set this up", and a caller has no use for knowing which half is missing.
function msTeamsUnconfigured() {
    return ['ok' => false, 'response' => new WP_REST_Response([
        'success' => false,
        'message' => 'The Microsoft Teams integration is not configured on this server.',
        'error' => 'msteams_unconfigured'
    ], 503)];
}

function msTeamsClient() {
    // The services file is loaded by the plugin loader, and ADR 0021 has that edit landing AFTER the
    // upload - so there is a window where these routes exist and the class does not. A missing class
    // throws Error, which is not an Exception and would escape the catch below as a 500 on every call.
    // Not loaded is not configured, so it answers the same 503 rather than reading as our own bug.
    if (!class_exists(MsGraphService::class)) { return msTeamsUnconfigured(); }
    try {
        return ['ok' => true, 'graph' => new MsGraphService()];
    } catch (Exception $e) {
        return msTeamsUnconfigured();
    }
}

// The effective default window, and whether an operator has moved it off the file default.
// Same shape as bookingLimitsConfig(): stored option wins, file default is the fallback, and
// a value that is not a sane number is ignored rather than trusted.
function msTeamsRangeConfig() {
    global $pe_msteams_range_past_days, $pe_msteams_range_future_days, $pe_msteams_settings_option;

    $stored = get_option($pe_msteams_settings_option, []);
    $stored = is_array($stored) ? $stored : [];

    $past = $stored['rangePastDays'] ?? null;
    $future = $stored['rangeFutureDays'] ?? null;

    $pastDays = (is_scalar($past) && preg_match('/^\d+$/', (string) $past) === 1)
        ? (int) $past : (int) $pe_msteams_range_past_days;
    $futureDays = (is_scalar($future) && preg_match('/^\d+$/', (string) $future) === 1)
        ? (int) $future : (int) $pe_msteams_range_future_days;

    return [
        'rangePastDays' => $pastDays,
        'rangeFutureDays' => $futureDays,
        'pastFileDefault' => (int) $pe_msteams_range_past_days,
        'futureFileDefault' => (int) $pe_msteams_range_future_days,
        'overridden' => ($pastDays !== (int) $pe_msteams_range_past_days)
            || ($futureDays !== (int) $pe_msteams_range_future_days)
    ];
}

// Shared 502 for a Graph call that failed, so both routes report an upstream problem the same way.
// getMessage() is safe to surface here: the service redacts the client secret and reduces the URL to a
// path before it ever builds one of these.
function msTeamsUpstreamResponse(Exception $e, $message) {
    return new WP_REST_Response([
        'success' => false,
        'message' => $message,
        'error' => $e->getMessage()
    ], 502);
}

// GET /teams/meetings?start=&end=[&organizer=]
// Teams meetings on the organizer's calendar in a window. Read from calendarView rather than
// onlineMeetings because that is the collection application permissions can enumerate - which is also
// why this one route needs Calendars.Read.All on top of the OnlineMeetings permissions.
function getTeamsMeetings(WP_REST_Request $request) {
    $organizer = msTeamsOrganizer($request);
    if ($organizer === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'An organizer is required.'
        ], 400);
    }

    // start and end are OPTIONAL as of this version, and each defaults on its own side. A caller
    // that already sends both is unaffected; one that sends neither gets the configured window
    // instead of a 400. Sending only one is legitimate - "everything since March" is a real
    // question - so the missing half falls back rather than rejecting the pair.
    $range = msTeamsRangeConfig();
    $start = $request->get_param('start');
    $end = $request->get_param('end');
    $startGiven = is_string($start) && trim($start) !== '';
    $endGiven = is_string($end) && trim($end) !== '';

    // A date this API cannot read is the CALLER's mistake, so it is parsed before the Graph call rather
    // than inside the same try. Both throw the same Exception type, and answering 502 for a mistyped
    // date would send whoever is debugging it at the wrong system entirely.
    try {
        // Built from midnight UTC rather than from now, so two calls on the same day return the
        // same window and a cached client answer does not drift by the minute.
        $today = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
        $startUtc = $startGiven
            ? MsTeamsHelper::toGraphDateTime(trim($start))
            : gmdate('Y-m-d\TH:i:s\Z', $today - ($range['rangePastDays'] * 86400));
        $endUtc = $endGiven
            ? MsTeamsHelper::toGraphDateTime(trim($end))
            : gmdate('Y-m-d\TH:i:s\Z', $today + ($range['rangeFutureDays'] * 86400));
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'start and end must be dates this API can read, e.g. 2026-08-21 or 2026-08-21T09:00:00Z. Both are optional - omit them for the server default window.',
            'error' => $e->getMessage()
        ], 400);
    }

    // A window that ends before it starts returns nothing and looks like "no meetings", which is
    // the wrong answer to a wrong question.
    if (strtotime($endUtc) <= strtotime($startUtc)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'end must be after start.'
        ], 400);
    }

    $client = msTeamsClient();
    if (!$client['ok']) {
        return $client['response'];
    }

    try {
        $meetings = (new MsTeamsMeetingsService($client['graph']))->listMeetingsInRange($organizer, $startUtc, $endUtc);
    } catch (Exception $e) {
        return msTeamsUpstreamResponse($e, 'Failed to read Teams meetings!');
    }

    // The window is echoed back because it is no longer necessarily the one the caller asked
    // for. "0 meetings" over a window nobody chose is unreadable without knowing the window.
    return new WP_REST_Response([
        'success' => true,
        'data' => $meetings,
        'window' => [
            'start' => $startUtc,
            'end' => $endUtc,
            'startDefaulted' => !$startGiven,
            'endDefaulted' => !$endGiven,
            'pastDays' => $range['rangePastDays'],
            'futureDays' => $range['rangeFutureDays']
        ],
        'message' => count($meetings) . ' Teams meeting(s) found.'
    ], 200);
}

// GET /teams/attendance?joinUrl=[&reportId=][&organizer=]
// Attendance for one meeting. reportId is optional: without it the newest occurrence is used, which is
// what a caller holding only a join URL wants out of a recurring meeting.
//
// Three outcomes that are not the same thing, and are answered differently on purpose:
//   404              no meeting matches that join URL for this organizer
//   200 + data null  the meeting exists, but Teams has generated no report for it yet
//   502              Graph itself failed
// Collapsing the middle one into a 404 would tell a caller their meeting does not exist minutes after
// they were in it, which is how "the integration is broken" reports get filed.
function getTeamsAttendance(WP_REST_Request $request) {
    $organizer = msTeamsOrganizer($request);
    if ($organizer === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'An organizer is required.'
        ], 400);
    }

    $joinUrl = $request->get_param('joinUrl');
    if (!is_string($joinUrl) || trim($joinUrl) === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'joinUrl is required.'
        ], 400);
    }

    $client = msTeamsClient();
    if (!$client['ok']) {
        return $client['response'];
    }

    try {
        $meeting = (new MsTeamsMeetingsService($client['graph']))->findMeetingByJoinUrl($organizer, trim($joinUrl));
    } catch (Exception $e) {
        return msTeamsUpstreamResponse($e, 'Failed to look up that Teams meeting!');
    }

    if (!$meeting || empty($meeting['id'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No Teams meeting matches that join URL for this organizer.'
        ], 404);
    }

    $attendance = new MsTeamsAttendanceService($client['graph']);
    $meetingId = (string) $meeting['id'];
    $requestedReport = $request->get_param('reportId');

    try {
        if (is_string($requestedReport) && trim($requestedReport) !== '') {
            $reportId = trim($requestedReport);
        } else {
            $newest = MsTeamsAttendanceService::newestReport($attendance->listReports($organizer, $meetingId));
            if ($newest === null) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => null,
                    'message' => 'That meeting has no attendance report yet.'
                ], 200);
            }
            $reportId = (string) $newest['id'];
        }
        $summary = $attendance->summarizeReport($organizer, $meetingId, $reportId);
    } catch (Exception $e) {
        return msTeamsUpstreamResponse($e, 'Failed to read the attendance report!');
    }

    return new WP_REST_Response([
        'success' => true,
        'data' => $summary,
        'message' => $summary['attendeeCount'] . ' attendee(s) in that report.'
    ], 200);
}

//----------------------END: MICROSOFT TEAMS--------------------------------------