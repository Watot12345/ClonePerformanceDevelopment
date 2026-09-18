<?php

require_once __DIR__ . '/../models/TrainingNeedModel.php';
require_once __DIR__ . '/../models/CompetencyModel.php';
require_once __DIR__ . '/../mailer.php';

class TrainingIntegrationController
{
    private TrainingNeedModel $needModel;
    private CompetencyModel $competencyModel;

    public function __construct()
    {
        $this->needModel = new TrainingNeedModel();
        $this->competencyModel = new CompetencyModel();
    }

    /**
     * Trigger all closed-loop ecosystem updates on successful training certification
     */
    public function handleCertificationSuccess(array $evaluationData, array $certificateData): array
    {
        $associateId = $evaluationData['associateId'] ?? ($evaluationData['associate_id'] ?? '');
        $associateName = $evaluationData['associateName'] ?? ($evaluationData['associate_name'] ?? 'Associate');
        $programTitle = $evaluationData['programTitle'] ?? ($evaluationData['program_title'] ?? 'Training Program');
        $programId = $evaluationData['programId'] ?? ($evaluationData['program_id'] ?? '');
        $certNumber = $certificateData['certificate_number'] ?? ($evaluationData['certificateReference'] ?? '');
        $competencyKey = $evaluationData['competencyKey'] ?? ($evaluationData['competency_key'] ?? '');
        $scoreAfter = (float)($evaluationData['competencyScoreAfter'] ?? ($evaluationData['competency_score_after'] ?? 4.80));
        $xpAwarded = (int)($evaluationData['xpAwarded'] ?? ($evaluationData['xp_awarded'] ?? 150));

        $results = [
            'competency_elevated' => false,
            'xp_awarded'          => $xpAwarded,
            'need_resolved'       => false,
            'email_dispatched'    => false,
            'certificate_number'  => $certNumber,
            'new_competency_score'=> $scoreAfter
        ];

        // 1. Resolve the SPECIFIC training need tied to this program + employee.
        //    updateStatus('Resolved') persists training_needs.status AND fires the
        //    cascade hook, which handles performance_goals updates automatically
        //    (sets in_training=false, needs_training=false, status='Done').
        $linkedNeedId = $evaluationData['linkedNeedId'] ?? null;
        if ($linkedNeedId) {
            $this->needModel->updateStatus($linkedNeedId, 'Resolved');
            $results['need_resolved'] = true;
        } else {
            $allNeeds = $this->needModel->getNeeds();
            foreach ($allNeeds as $need) {
                $nEmpId  = $need['employeeId'] ?? ($need['employee_id'] ?? '');
                $nProgId = $need['linked_program_id'] ?? ($need['linkedProgramId'] ?? '');
                
                if ($programId !== '' && $nEmpId === $associateId && $nProgId === $programId) {
                    $this->needModel->updateStatus($need['id'], 'Resolved');
                    $results['need_resolved'] = true;
                    break; // one need per program per employee
                }
            }
        }

        // 2. Mark all assessed competency deficits as elevated (>= 4.80 Benchmark Met) in Supabase
        if (!empty($associateId)) {
            if (!empty($competencyKey)) {
                $this->competencyModel->setScore($associateId, $competencyKey, $scoreAfter);
            }
            $this->competencyModel->elevateAllDeficitsForEmployee($associateId, $scoreAfter);
            $results['competency_elevated'] = true;
        }

        // 3. Record verified Training Certification XP into unified xp_ledger
        if ($xpAwarded > 0 && !empty($associateId)) {
            require_once __DIR__ . '/../models/SocialModel.php';
            $socialModel = new SocialModel();
            $socialModel->createTrainingCertGrant($associateId, $xpAwarded, $programTitle, $certNumber);
            $results['xp_synced_to_ledger'] = true;
        }

        // 4. Performance goal updates — handled by cascade hook inside
        //    TrainingNeedModel::updateStatus('Resolved') → onTrainingNeedStatusChanged()
        //    which sets in_training=false, needs_training=false, status='Done'
        //    on the linked goal via target_goal_id. No manual updates needed.

        // 5. Email dispatch omitted as per requirement
        $results['email_dispatched'] = false;

        return $results;
    }
}
