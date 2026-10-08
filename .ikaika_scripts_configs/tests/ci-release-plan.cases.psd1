# The Build release form's answers ci-check plays through ci-release-plan.ps1, and what each must turn into.
# Exit is the plan's exit code; Plan holds build-release's arguments, Outputs the variables the job reads, Absent arguments that must not appear, Says text the output must contain.
# Version, package and upload are steps of their own: VERSION_BUMP goes to tag-version -Bump, the plan to build-release, DEPLOY_PLAN to server/deploy-release.
# Ref is the branch or tag the run starts from; the artifact's name carries its name.
@{
    DefaultRef = 'refs/heads/2806'
    Cases = @(
        @{ Name = 'defaults: a staging package, nothing uploaded'; Answers = @{}; Exit = 0
            Plan = @{ Debug = $true }; Outputs = @{ PURPOSES = ''; TAGGING = 'false'; VERSION_BUMP = ''; UPLOADING = 'false'; DEPLOY_PLAN = ''; BUILD_DEBUG = 'true'; ARTIFACT_NAME = 'portal-api-staging-2806' }
            Absent = @('Bump', 'Tag', 'Deploy', 'Force') }
        @{ Name = 'a staging upload'; Answers = @{ upload = $true }; Exit = 0; Outputs = @{ PURPOSES = 'deploy-staging'; UPLOADING = 'true'; DEPLOY_PLAN = '{"Debug":true,"Force":true}' } }
        @{ Name = 'a patch bump commits and tags'; Answers = @{ version = 'patch'; upload = $true }; Exit = 0; Outputs = @{ TAGGING = 'true'; VERSION_BUMP = 'patch' }; Absent = @('Bump', 'Tag') }
        @{ Name = 'keep takes the next build number only'; Answers = @{ version = 'keep' }; Exit = 0; Outputs = @{ TAGGING = 'true'; VERSION_BUMP = 'keep' } }
        @{ Name = 'a custom version'; Answers = @{ version = 'custom'; custom_version = '1.2.0' }; Exit = 0; Outputs = @{ VERSION_BUMP = '1.2.0' } }
        @{ Name = 'a custom version that is not x.y.z is refused'; Answers = @{ version = 'custom'; custom_version = '1.2' }; Exit = 1; Says = 'custom_version must be x.y.z' }
        @{ Name = 'a bump from a tag is refused'; Answers = @{ version = 'patch' }; Ref = 'refs/tags/v1.0.0+1'; Exit = 1; Says = 'A version bump is committed to a branch' }
        # There is no production yet; the plan says so rather than building for a folder nobody named.
        @{ Name = 'production with no production set up is refused'; Answers = @{ target = 'production'; upload = $true; confirm_production = $true }; Exit = 1; Says = 'There is no production to build for yet' }
        @{ Name = 'a target the form cannot send is refused'; Answers = @{ target = 'qa' }; Exit = 1; Says = "target is 'qa'" }
    )
}
