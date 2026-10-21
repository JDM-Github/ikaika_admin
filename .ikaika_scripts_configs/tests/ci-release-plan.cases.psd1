# The Build release form's answers ci-check plays through ci-release-plan.ps1, and what each must turn into.
# Exit is the plan's exit code; Plan holds build-release's arguments, Outputs the variables the job reads, Absent arguments that must not appear, Says text the output must contain.
# Build, tag and upload are steps of their own, in the estimator's order: the plan - with Bump when versioning - goes to build-release, TAGGING to
# tag-version once the build is good, DEPLOY_PLAN to server/deploy-release once the tag is; VERSION_BUMP names the bump for the summary.
# Ref is the branch or tag the run starts from; the artifact's name carries its name.
@{
    DefaultRef = 'refs/heads/2806'
    Cases = @(
        @{ Name = 'defaults: a staging package, nothing uploaded'; Answers = @{}; Exit = 0
            Plan = @{ Debug = $true }; Outputs = @{ PURPOSES = ''; TAGGING = 'false'; VERSION_BUMP = ''; UPLOADING = 'false'; DEPLOY_PLAN = ''; BUILD_DEBUG = 'true'; ARTIFACT_NAME = 'backend-staging-2806'; RETENTION = '3' }
            Absent = @('Bump', 'Tag', 'Deploy', 'Force') }
        @{ Name = 'a staging upload'; Answers = @{ upload = $true }; Exit = 0; Outputs = @{ PURPOSES = 'deploy-staging'; UPLOADING = 'true'; DEPLOY_PLAN = '{"Debug":true,"Force":true}' } }
        # As the estimator's: build-release bumps as it builds, and tag-version commits and tags once the build is good.
        @{ Name = 'a patch bump is built first, then tagged'; Answers = @{ version = 'patch'; upload = $true }; Exit = 0; Plan = @{ Debug = $true; Bump = 'patch' }; Outputs = @{ TAGGING = 'true'; VERSION_BUMP = 'patch' }; Absent = @('Tag'); Says = '- Upload | Once the tag is on Bitbucket' }
        @{ Name = 'keep takes the next build number only'; Answers = @{ version = 'keep' }; Exit = 0; Plan = @{ Bump = 'keep' }; Outputs = @{ TAGGING = 'true'; VERSION_BUMP = 'keep' } }
        @{ Name = 'a custom version'; Answers = @{ version = 'custom'; custom_version = '1.2.0' }; Exit = 0; Plan = @{ Bump = '1.2.0' }; Outputs = @{ VERSION_BUMP = '1.2.0' } }
        @{ Name = 'a custom version that is not x.y.z is refused'; Answers = @{ version = 'custom'; custom_version = '1.2' }; Exit = 1; Says = 'custom_version must be x.y.z' }
        @{ Name = 'a bump from a tag is refused'; Answers = @{ version = 'patch' }; Ref = 'refs/tags/v1.0.0+1'; Exit = 1; Says = 'A version bump is committed to a branch' }
        # A production upload is a release (2026-10-21, user): a version, tagged v<version>-released, and its box ticked.
        @{ Name = 'a production release is bumped, tagged and uploaded'; Answers = @{ target = 'production'; version = 'keep'; upload = $true; confirm_production = $true }; Exit = 0
            Plan = @{ Debug = $false; Bump = 'keep' }; Outputs = @{ PURPOSES = 'deploy-production'; TAGGING = 'true'; DEPLOY_PLAN = '{"Debug":false,"Force":true}' }; Says = 'tagged as a production release' }
        @{ Name = 'a production upload of same is refused'; Answers = @{ target = 'production'; upload = $true; confirm_production = $true }; Exit = 1; Says = 'A production upload is a release' }
        @{ Name = 'production without its confirmation is refused'; Answers = @{ target = 'production'; version = 'keep'; upload = $true }; Exit = 1; Says = 'Tick "Yes, upload to PRODUCTION"' }
        @{ Name = 'a production dry run needs no version and no box'; Answers = @{ target = 'production'; upload = $true; dry_run = $true }; Exit = 0; Outputs = @{ TAGGING = 'false'; DEPLOY_PLAN = '{"Debug":false,"Force":true,"DryRun":true}' } }
        # Build from a tag (2026-10-21, user): the tag rebuilt as it was, for staging only, never bumped or tagged.
        @{ Name = 'a tag rebuilt for staging'; Answers = @{ build_from = 'v1.0.0+3' }; Exit = 0; Plan = @{ Debug = $true; FromTag = 'v1.0.0+3' }; Outputs = @{ BUILD_FROM = 'v1.0.0+3'; TAGGING = 'false'; RELEASING = 'false' }; Absent = @('Bump'); Says = 'v1.0.0+3 rebuilt as it was' }
        @{ Name = 'a tag rebuilt and uploaded to staging'; Answers = @{ build_from = 'v1.0.0+3'; upload = $true }; Exit = 0; Outputs = @{ UPLOADING = 'true'; DEPLOY_PLAN = '{"Debug":true,"Force":true}'; BUILD_FROM = 'v1.0.0+3' } }
        @{ Name = 'a rebuild with a bump is refused'; Answers = @{ build_from = 'v1.0.0+3'; version = 'patch' }; Exit = 1; Says = 'A rebuild from a tag never bumps' }
        @{ Name = 'a rebuild for production is refused'; Answers = @{ build_from = 'v1.0.0+3'; target = 'production' }; Exit = 1; Says = 'A tag is rebuilt for staging only' }
        @{ Name = 'a build_from that is no tag name is refused'; Answers = @{ build_from = '-v1' }; Exit = 1; Says = "build_from '-v1' is not a tag name" }
        @{ Name = 'without build_from nothing is rebuilt'; Answers = @{}; Exit = 0; Outputs = @{ BUILD_FROM = '' }; Absent = @('FromTag') }
        @{ Name = 'a target the form cannot send is refused'; Answers = @{ target = 'qa' }; Exit = 1; Says = "target is 'qa'" }
        # A bump is kept as a GitHub release, where it costs no Actions storage; anything else stays an artifact.
        @{ Name = 'a bump is kept as a GitHub release, its artifact a day'; Answers = @{ version = 'patch' }; Exit = 0; Outputs = @{ RELEASING = 'true'; RETENTION = '1' }; Says = '| Kept as | a GitHub release' }
        @{ Name = 'github_release off keeps a bump as an artifact, three days'; Answers = @{ version = 'patch'; github_release = $false }; Exit = 0; Outputs = @{ RELEASING = 'false'; RETENTION = '3' } }
        @{ Name = 'a build that keeps its version has no tag to release under'; Answers = @{}; Exit = 0; Outputs = @{ RELEASING = 'false' }; Says = 'kept as an artifact instead' }
        # dry_run, as the project estimator's: names what would go up and run, sends nothing, and needs no production box.
        @{ Name = 'a dry-run upload sends nothing'; Answers = @{ upload = $true; dry_run = $true }; Exit = 0; Outputs = @{ UPLOADING = 'true'; DEPLOY_PLAN = '{"Debug":true,"Force":true,"DryRun":true}' } }
        @{ Name = 'dry run and clean without an upload are dropped'; Answers = @{ dry_run = $true; clean = $true }; Exit = 0; Outputs = @{ UPLOADING = 'false'; DEPLOY_PLAN = '' }; Says = 'Dry run and clean apply only with'; Absent = @('Clean') }
        # clean, as the estimator's: the upload clears older backups, through the archive's deploy.json, so build-release carries it.
        @{ Name = 'an upload that cleans the server'; Answers = @{ upload = $true; clean = $true }; Exit = 0; Plan = @{ Debug = $true; Clean = $true }; Outputs = @{ DEPLOY_PLAN = '{"Debug":true,"Force":true}' }; Says = ', clearing older backups' }
    )
}
