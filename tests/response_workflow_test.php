<?php
require_once __DIR__ . '/../includes/reports/response-workflow.php';

function expectResponseWorkflowCondition($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$allowedTransitions = [
    ['authorize_high_risk_override', 'staff', 'awaiting_dcvs_decision'],
    ['return_for_validation', 'staff', 'awaiting_dcvs_decision'],
    ['reject_report', 'staff', 'awaiting_dcvs_decision'],
    ['start_response', 'barangay', 'barangay_response_approved'],
    ['record_site_visit', 'barangay', 'barangay_responding'],
    ['mark_secured', 'barangay', 'barangay_responding'],
    ['update_progress', 'barangay', 'barangay_responding'],
    ['update_progress', 'barangay', 'animal_secured'],
    ['resolve_barangay', 'barangay', 'animal_secured'],
    ['unable_to_secure', 'barangay', 'barangay_response_approved'],
    ['unable_to_secure', 'barangay', 'barangay_responding'],
    ['unable_to_locate', 'barangay', 'barangay_responding'],
    ['mark_secured', 'barangay', 'barangay_responding'],
    ['request_pickup', 'barangay', 'animal_secured'],
    ['set_handover_plan', 'barangay', 'resolved'],
    ['revoke_approval', 'staff', 'barangay_response_approved'],
    ['revoke_approval', 'staff', 'barangay_responding'],
    ['confirm_turnover', 'staff', 'awaiting_turnover'],
    ['begin_processing', 'staff', 'turned_over'],
    ['return_to_barangay', 'staff', 'animal_secured'],
    ['return_to_barangay', 'staff', 'awaiting_turnover'],
    ['return_to_barangay', 'staff', 'turned_over'],
    ['switch_to_dispatch', 'staff', 'animal_secured'],
    ['switch_to_dispatch', 'staff', 'awaiting_turnover'],
    ['resume_barangay_response', 'barangay', 'returned_to_barangay'],
    ['close_not_actionable', 'staff', 'unable_to_locate'],
    ['close_not_actionable', 'staff', 'escalated_to_dcvs'],
    ['reopen_unable_to_locate', 'staff', 'unable_to_locate'],
    ['dispatch_escalation', 'staff', 'escalated_to_dcvs'],
];
foreach ($allowedTransitions as [$action, $role, $state]) {
    expectResponseWorkflowCondition(
        dcvsResponseCanTransition($action, $role, $state),
        "Expected {$role} to perform {$action} from {$state}."
    );
}

$deniedTransitions = [
    ['authorize_high_risk_override', 'barangay', 'awaiting_dcvs_decision'],
    ['return_for_validation', 'barangay', 'awaiting_dcvs_decision'],
    ['reject_report', 'staff', 'barangay_response_approved'],
    ['start_response', 'staff', 'barangay_response_approved'],
    ['mark_secured', 'staff', 'barangay_responding'],
    ['confirm_turnover', 'barangay', 'awaiting_turnover'],
    ['begin_processing', 'barangay', 'turned_over'],
    ['resume_barangay_response', 'staff', 'returned_to_barangay'],
    ['dispatch_escalation', 'barangay', 'escalated_to_dcvs'],
    ['request_pickup', 'barangay', 'barangay_responding'],
    ['set_handover_plan', 'staff', 'resolved'],
    ['set_handover_plan', 'barangay', 'animal_secured'],
    ['start_response', 'barangay', 'barangay_responding'],
    ['record_site_visit', 'staff', 'barangay_responding'],
    ['record_site_visit', 'barangay', 'barangay_response_approved'],
    ['mark_secured', 'barangay', 'barangay_response_approved'],
    ['resolve_barangay', 'barangay', 'barangay_responding'],
    ['update_progress', 'barangay', 'awaiting_turnover'],
    ['mark_secured', 'barangay', 'resolved'],
    ['unknown_action', 'staff', 'awaiting_dcvs_decision'],
];
foreach ($deniedTransitions as [$action, $role, $state]) {
    expectResponseWorkflowCondition(
        !dcvsResponseCanTransition($action, $role, $state),
        "Unexpectedly allowed {$role} to perform {$action} from {$state}."
    );
}

expectResponseWorkflowCondition(dcvsResponseCanResolveCase('staff', 'dcvs_dispatch'), 'DCVS may resolve a dispatched case.');
expectResponseWorkflowCondition(dcvsResponseCanResolveCase('staff', 'dcvs_processing'), 'DCVS may resolve a processed turnover.');
expectResponseWorkflowCondition(!dcvsResponseCanResolveCase('staff', 'awaiting_turnover'), 'A case awaiting turnover must not be resolved.');
expectResponseWorkflowCondition(!dcvsResponseCanResolveCase('barangay', 'dcvs_processing'), 'Barangay may not resolve a DCVS case.');
expectResponseWorkflowCondition(dcvsResponseRequiresTurnover('injured_animal', 'rescued'), 'A rescued live animal requires turnover.');
expectResponseWorkflowCondition(dcvsResponseRequiresTurnover('stray_animal', 'under_veterinary_care'), 'A live animal under veterinary care requires turnover.');
expectResponseWorkflowCondition(dcvsResponseRequiresTurnover('stray_animal', ''), 'A resolved live-animal case without a legacy disposition still requires turnover.');
expectResponseWorkflowCondition(dcvsResponseRequiresTurnover('stray_animal', 'legacy_unknown_disposition'), 'A resolved live-animal case with a legacy disposition still requires turnover.');
expectResponseWorkflowCondition(!dcvsResponseRequiresTurnover('dead_animal', 'disposed_per_local_protocol'), 'A dead animal does not require live-animal turnover.');
expectResponseWorkflowCondition(!dcvsResponseRequiresTurnover('injured_animal', 'reunited_with_owner'), 'An animal reunited with its owner does not require DCVS turnover.');
expectResponseWorkflowCondition(!dcvsResponseRequiresTurnover('injured_animal', 'transferred_to_shelter'), 'An animal already transferred to a shelter does not require DCVS turnover.');
expectResponseWorkflowCondition(dcvsResponseHasBarangayProposal(['barangay_proposed' => 1]), 'Legacy proposal rows should remain identifiable during migration.');
expectResponseWorkflowCondition(
    dcvsResponseHasBarangayProposal([
        'barangay_proposed' => 1,
        'report_type' => 'rabies',
        'urgency_level' => 'critical',
        'animal_count' => '6+',
        'animal_behavior' => 'Aggressive',
    ]),
    'Legacy proposal flags should remain identifiable even when the report has high-risk flags.'
);
expectResponseWorkflowCondition(!dcvsResponseHasBarangayProposal(['barangay_proposed' => 0]), 'Staff may not approve a response that was not proposed.');

expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['report_type' => 'rabies']),
    'Rabies reports must be classified high-risk.'
);
expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['animal_count' => '6']),
    'Reports involving six animals must be classified high-risk.'
);
expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['animal_behavior' => 'Aggressive']),
    'Aggressive-animal reports must be classified high-risk.'
);
expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['urgency_level' => 'critical']),
    'Critical-urgency reports must be classified high-risk.'
);
expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['urgency_level' => 'high']),
    'High-urgency reports must be classified high-risk.'
);
expectResponseWorkflowCondition(
    dcvsResponseIsHighRisk(['animal_count' => '6+']),
    'Reports involving six or more animals must be classified high-risk.'
);
expectResponseWorkflowCondition(
    !dcvsResponseIsHighRisk(['animal_count' => '1', 'animal_behavior' => 'Calm']),
    'A single calm animal should not be classified high-risk.'
);

echo "Response workflow transition and risk checks passed.\n";
