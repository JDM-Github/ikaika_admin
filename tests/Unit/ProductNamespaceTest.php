<?php

namespace Tests\Unit;

use App\Modules\Portal\Models\Project as PortalProject;
use App\Modules\ProjectEstimator\Models\Project as EstimatorProject;
use Tests\TestCase;

class ProductNamespaceTest extends TestCase
{
    public function test_portal_and_estimator_can_both_have_a_project_model(): void
    {
        $this->assertSame('portal', PortalProject::productKey());
        $this->assertSame('project-estimator', EstimatorProject::productKey());
        $this->assertNotSame(PortalProject::class, EstimatorProject::class);
    }
}
