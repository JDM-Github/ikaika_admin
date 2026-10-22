<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The local .env holds a token scoped to the TEST base only; production sets its own PROD token and base
    // (deployment/AIRTABLE-SYNC.md). `scheduled` turns the minute sync on, once the first send was read.
    'airtable' => [
        'token' => env('AIRTABLE_TOKEN'),
        'base' => env('AIRTABLE_BASE_ID'),
        'scheduled' => (bool) env('AIRTABLE_SYNC_SCHEDULED', false),
    ],

    // A read-only Bitbucket access token for MIS projects' code activity; it lives in .env alone.
    'bitbucket' => [
        'token' => env('BITBUCKET_TOKEN'),
        // Signs each push webhook delivery; unset, every delivery is refused.
        'webhook_secret' => env('BITBUCKET_WEBHOOK_SECRET'),
        'base_url' => 'https://api.bitbucket.org/2.0',
    ],

    // Jira Cloud for Issues & Requests (2026-10-19): a service account's API token creates an issue per report
    // in one project and reads its progress back. Production and the Test Server share that project (2026-10-21,
    // user): each follows only the issues it filed, and staging's carry a staging label (PortalJiraEnvironment).
    // With any of base URL, email, token or project unset, nothing goes to Jira.
    'jira' => [
        'base_url' => env('JIRA_BASE_URL'),
        // A scoped API token is refused at the site and answered only through Atlassian's gateway for it, which
        // names the site by this id; a classic token needs none (portal:jira-check prints it on a 401).
        'cloud_id' => env('JIRA_CLOUD_ID'),
        'email' => env('JIRA_EMAIL'),
        'token' => env('JIRA_API_TOKEN'),
        'project' => env('JIRA_PROJECT_KEY'),
        // Signs each webhook delivery (POST /api/<channel>/jira/webhook); unset, every delivery is refused.
        'webhook_secret' => env('JIRA_WEBHOOK_SECRET'),
        // Every issue the portal makes carries it, and the admin webhook's JQL filters on it.
        'label' => 'ikaika-portal',
        // The API channel that files as it always has; any other adds its channel as a second label.
        'production_channel' => 'production',
        // Triage (2026-10-21, user): a duplicate is labelled so, and linked to its original's issue by this type.
        'duplicate_label' => 'duplicate',
        'duplicate_link_type' => 'Duplicate',
        // A report whose level changed is filed anew (2026-10-22, user); its old issue is closed with this label and
        // linked to the new one by this type.
        'moved_label' => 'moved',
        'moved_link_type' => 'Relates',
        // The issue type each kind is filed as, by name; a project without one falls back to Task -- but an epic,
        // which is Epic or nothing (2026-10-22, user).
        'issue_types' => [
            'bug' => env('JIRA_ISSUE_TYPE_BUG', 'Bug'),
            'feature' => env('JIRA_ISSUE_TYPE_FEATURE', 'Story'),
            'feedback' => env('JIRA_ISSUE_TYPE_FEEDBACK', 'Task'),
            'epic' => env('JIRA_ISSUE_TYPE_EPIC', 'Epic'),
        ],
        // A report's plan is the issue's too (2026-10-22, user). The site's Team and Start date fields, by id
        // (customfield_N), are found by their type and name unless set here.
        'fields' => [
            'team' => env('JIRA_FIELD_TEAM'),
            'start_date' => env('JIRA_FIELD_START_DATE'),
        ],
        // The team every new issue is filed under, by its name; blank, none.
        'default_team' => env('JIRA_DEFAULT_TEAM', 'DEV'),
    ],

    // ID-number sign-in on a deployed portal: only MIS knows it; unset, ID sign-in stays refused there.
    'portal_id_login' => [
        'password' => env('PORTAL_ID_LOGIN_PASSWORD'),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'ipwhois' => [
        'base_url' => env('PORTAL_IPWHOIS_URL', 'https://ipwho.is'),
        'lookup_private' => filter_var(
            env('PORTAL_IP_LOOKUP_PRIVATE', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    'ipapi' => [
        'base_url' => env('PORTAL_IP_GEOLOCATION_URL', 'https://ipapi.co'),
    ],

    'portal_azure' => [
        'tenant_id' => env('PORTAL_AZURE_TENANT_ID'),
        'client_id' => env('PORTAL_AZURE_CLIENT_ID'),
        'client_secret' => env('PORTAL_AZURE_CLIENT_SECRET'),
        'redirect_uri' => env('PORTAL_AZURE_REDIRECT_URI', 'http://localhost:8081'),
    ],

    // The remark formatter (Report remarks -> Date/What/Changed/Wins). Keyless, it stays switched
    // off; a "-cloud" model needs the hosted API, so the key is the account's OLLAMA_API_KEY.
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'https://ollama.com'),
        'key' => env('OLLAMA_API_KEY'),
        'model' => env('OLLAMA_MODEL', 'gemma4:31b-cloud'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 30),
    ],

];
