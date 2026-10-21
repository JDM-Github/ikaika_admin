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
        # Production has been set up since 2026-10-15 (release.assetUrl): with its box ticked it builds and uploads as staging does.
        @{ Name = 'production with its box ticked is built and uploaded'; Answers = @{ target = 'production'; upload = $true; confirm_production = $true }; Exit = 0; Plan = @{ Debug = $false }; Outputs = @{ PURPOSES = 'deploy-production'; DEPLOY_PLAN = '{"Debug":false,"Force":true}' } }
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
