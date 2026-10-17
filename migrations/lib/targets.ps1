# The Airtable exporter targets, in one place, so migrate-all.ps1,
# verify_migrations.ps1 and copy-to-sql.ps1 can never disagree about which base
# id, outputs folder or verifier base belongs to a product.
#
# -Env test|prod selects which Airtable base is read. The estimator has a single
# base (no test copy), so it is the same either way.
#
#   Product   Folder (outputs/)        Target (migrate.ps1 -Target)   Verify (verify_migration.py --base)
#   portal    users_projects_<env>      users-projects-<env>           users-projects
#   estimator project_estimator         project-estimator              project-estimator
#   tracker   transaction_tracker_<env> transaction-tracker-<env>      transaction-tracker
#
# DevDb is the local database each product's schema.sql targets (the committed
# "example" name). copy-to-sql rewrites the generated `USE` back to it so a prod
# build never leaves a production database name in the committed sql/ folder.

function Get-MigrationTargets {
    param([ValidateSet('test', 'prod')][string]$Env = 'test')

    switch ($Env) {
        'prod' {
            $up = @{ Target = 'users-projects-prod'; Folder = 'users_projects_prod'; BaseId = 'app8jGTzRDQt4yPIa' }
            $tt = @{ Target = 'transaction-tracker-prod'; Folder = 'transaction_tracker_prod'; BaseId = 'appp5MogesHN2eVvG' }
        }
        'test' {
            $up = @{ Target = 'users-projects-test'; Folder = 'users_projects_test'; BaseId = 'app8DvnFZErPZT5Az' }
            $tt = @{ Target = 'transaction-tracker-test'; Folder = 'transaction_tracker_test'; BaseId = 'appYwnzEVZVXwH1hB' }
        }
    }

    return @(
        @{
            Product = 'portal'; Folder = $up.Folder; Target = $up.Target; BaseId = $up.BaseId
            Verify = 'users-projects'; SqlDir = 'sql/portal'; DevDb = 'test_portal_database'
            Label = 'Users & Projects'
        },
        @{
            Product = 'estimator'; Folder = 'project_estimator'; Target = 'project-estimator'; BaseId = 'appgh0Mki2uHDLAQY'
            Verify = 'project-estimator'; SqlDir = 'sql/project_estimator'; DevDb = 'test_estimator_database'
            Label = 'Project Estimator'
        },
        @{
            Product = 'tracker'; Folder = $tt.Folder; Target = $tt.Target; BaseId = $tt.BaseId
            Verify = 'transaction-tracker'; SqlDir = 'sql/transaction_tracker'; DevDb = 'test_tracker_database'
            Label = 'Transaction Tracker'
        }
    )
}
