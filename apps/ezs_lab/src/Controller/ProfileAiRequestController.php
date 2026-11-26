<?php

declare(strict_types=1);
namespace EZSLab\Controller;

use EZSLab\Service\LabAnalysisTokenGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileAiRequestController extends AbstractController
{
    public function __construct(private readonly LabAnalysisTokenGuard $guard) {}

    #[Route('/internal/analysis/desktop/profile/{id<\d+>}/scientific-items',name:'lab_internal_profile_scientific_items',methods:['GET'])]
    public function scientificItems(Request $request,int $id,\EZSLab\Service\AnalysisRunRegistry $runs): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $run=$runs->run($id);
        if (!$run || ($run['item']??null)!=='profile' || ($run['state']??null)!=='done') {
            return $this->json(['error'=>'completed_profile_run_required'],404);
        }
        $diagnostics=$run['diagnostics']??[];
        $modules=$diagnostics['module_registry']['modules']??null;
        if (!is_array($modules) || !array_is_list($modules)) {
            return $this->json(['error'=>'scientific_module_registry_missing'],409);
        }
        $items=[]; $seen=[];
        foreach ($modules as $index=>$module) {
            $key=is_array($module)?($module['id']??null):null;
            if (!is_string($key) || $key==='' || isset($seen[$key])) {
                return $this->json(['error'=>'scientific_module_identity_invalid'],409);
            }
            $seen[$key]=true;
            $items[]=['index'=>$index+1,'item_id'=>$key,'label'=>$module['name']??$key,
                'family'=>$module['family']??null,'status'=>$module['status']??null,
                'source_pointer'=>'/diagnostics/module_registry/modules/'.$index,
                'module'=>$module];
        }
        return $this->json(['schema'=>'ezstudio.profile-scientific-items.r1',
            'run_id'=>$id,'item_total'=>count($items),'items'=>$items,
            'coverage'=>['registry_total'=>count($modules),'returned'=>count($items),
                'complete_for_registry'=>true,'database_exhaustiveness_verified'=>false]]);
    }

}
