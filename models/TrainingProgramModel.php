<?php

require_once __DIR__ . '/BaseModel.php';

class TrainingProgramModel extends BaseModel
{
    public function __construct()
    {
        parent::__construct('training_programs');
    }

    public function getPrograms(array $filters = []): array
    {
        $all = $this->all($filters);
        if (empty($all)) {
            $baseline = $this->getBaselinePrograms();
            foreach ($baseline as $bp) {
                $this->createProgram($bp);
            }
            $all = $this->all($filters);
        }

        foreach ($all as &$p) {
            $p['id'] = $p['id'] ?? '';
            $p['targetCompetency'] = $p['target_competency'] ?? ($p['targetCompetency'] ?? 'Core Hospitality');
            $p['competencyKey'] = $p['competency_key'] ?? ($p['competencyKey'] ?? 'general');
            $p['categoryType'] = $p['category_type'] ?? ($p['categoryType'] ?? 'skill_gap');
            $p['trainerType'] = $p['trainer_type'] ?? ($p['trainerType'] ?? 'Internal Master Trainer');
            $p['passingScore'] = (int)($p['passing_score'] ?? ($p['passingScore'] ?? 8));
            $p['xpAward'] = (int)($p['xp_award'] ?? ($p['xpAward'] ?? 150));
            $p['badgeColor'] = $p['badge_color'] ?? ($p['badgeColor'] ?? 'primary');
            if (is_string($p['modules'] ?? null)) {
                $p['modules'] = json_decode($p['modules'], true) ?: [];
            }
            if (is_string($p['quiz_questions'] ?? null)) {
                $p['quizQuestions'] = json_decode($p['quiz_questions'], true) ?: [];
            } else {
                $p['quizQuestions'] = $p['quiz_questions'] ?? ($p['quizQuestions'] ?? []);
            }
        }
        return $all;
    }

    public function getProgramById(string $programId): ?array
    {
        $prog = $this->find($programId);
        if (!$prog) {
            $all = $this->getPrograms();
            foreach ($all as $p) {
                if ($p['id'] === $programId) return $p;
            }
            return null;
        }

        $prog['targetCompetency'] = $prog['target_competency'] ?? ($prog['targetCompetency'] ?? 'Core Hospitality');
        $prog['competencyKey'] = $prog['competency_key'] ?? ($prog['competencyKey'] ?? 'general');
        $prog['categoryType'] = $prog['category_type'] ?? ($prog['categoryType'] ?? 'skill_gap');
        $prog['trainerType'] = $prog['trainer_type'] ?? ($prog['trainerType'] ?? 'Internal Master Trainer');
        $prog['passingScore'] = (int)($prog['passing_score'] ?? ($prog['passingScore'] ?? 8));
        $prog['xpAward'] = (int)($prog['xp_award'] ?? ($prog['xpAward'] ?? 150));
        $prog['badgeColor'] = $prog['badge_color'] ?? ($prog['badgeColor'] ?? 'primary');
        if (is_string($prog['modules'] ?? null)) {
            $prog['modules'] = json_decode($prog['modules'], true) ?: [];
        }
        if (is_string($prog['quiz_questions'] ?? null)) {
            $prog['quizQuestions'] = json_decode($prog['quiz_questions'], true) ?: [];
        } else {
            $prog['quizQuestions'] = $prog['quiz_questions'] ?? ($prog['quizQuestions'] ?? []);
        }

        return $prog;
    }

    public function createProgram(array $data): array
    {
        $id = $data['id'] ?? ('prog-' . substr(bin2hex(random_bytes(3)), 0, 6));
        $modules = $data['modules'] ?? [];
        if (is_string($modules)) $modules = json_decode($modules, true) ?: [];
        $quiz = $data['quiz_questions'] ?? ($data['quizQuestions'] ?? []);
        if (is_string($quiz)) $quiz = json_decode($quiz, true) ?: [];

        $clean = [
            'id'                => $id,
            'title'             => $data['title'] ?? 'Training Program',
            'category'          => $data['category'] ?? 'Service Excellence',
            'category_type'     => in_array($data['category_type'] ?? ($data['categoryType'] ?? ''), ['skill_gap', 'compliance']) ? ($data['category_type'] ?? $data['categoryType']) : 'skill_gap',
            'dept'              => $data['dept'] ?? 'Front Office',
            'target_competency' => $data['target_competency'] ?? ($data['targetCompetency'] ?? 'Guest Relations & VIP Protocol'),
            'competency_key'    => $data['competency_key'] ?? ($data['competencyKey'] ?? 'guest_relations'),
            'duration'          => $data['duration'] ?? '3.5 Hours',
            'format'            => $data['format'] ?? 'Workshop & Roleplay',
            'trainer_type'      => $data['trainer_type'] ?? ($data['trainerType'] ?? 'Internal Master Trainer'),
            'passing_score'     => (int)($data['passing_score'] ?? ($data['passingScore'] ?? 8)),
            'xp_award'          => (int)($data['xp_award'] ?? ($data['xpAward'] ?? 150)),
            'icon'              => $data['icon'] ?? 'fa-graduation-cap',
            'badge_color'       => $data['badge_color'] ?? ($data['badgeColor'] ?? 'primary'),
            'description'       => $data['description'] ?? 'Comprehensive hotel training syllabus.',
            'modules'           => $modules,
            'quiz_questions'    => $quiz,
            'created_at'        => date('c')
        ];

        return $this->create($clean);
    }

    public function getBaselinePrograms(): array
    {
        return [
            [
                'id' => 'prog-1',
                'title' => 'Hospitality Crisis Diplomacy & Guest De-escalation',
                'category' => 'Skill Gap & Service Excellence',
                'categoryType' => 'skill_gap',
                'dept' => 'Front Office',
                'targetCompetency' => 'Guest Complaint Handling & VIP Protocol',
                'competencyKey' => 'guest_complaint_handling',
                'duration' => '3.5 Hours (1 Day Workshop)',
                'format' => 'In-Person Workshop & Roleplay',
                'trainerType' => 'Internal Master Trainer',
                'passingScore' => 8,
                'xpAward' => 150,
                'icon' => 'fa-shield-halved',
                'badgeColor' => 'primary',
                'description' => 'De-escalation protocols, empathy scripting, and service recovery compensation authority.',
                'modules' => ['1. Active Listening & Empathy', '2. Service Recovery Matrix', '3. Roleplay Simulation', '4. Post-Training Evaluation'],
                'quizQuestions' => [
                    ['q' => 'What is the benchmark standard response time for VIP guest requests?', 'options' => ['Within 5 minutes', 'Within 30 minutes', 'By end of shift', 'Next morning'], 'correct' => 0],
                    ['q' => 'Which protocol must be followed when a guest escalates a service delay?', 'options' => ['Listen and execute immediate service recovery voucher', 'Escalate immediately to GM without apology', 'Ask guest to wait in the lounge', 'Ignore the delay'], 'correct' => 0],
                    ['q' => 'What does the "A" in the LAST hospitality recovery framework represent?', 'options' => ['Argue company policies firmly', 'Apologize sincerely with empathy without assigning blame', 'Ask the security team to intervene', 'Assess financial liability immediately'], 'correct' => 1],
                    ['q' => 'When a guest raises their voice in the lobby, the recommended verbal cadence is:', 'options' => ['Speak louder than the guest to assert authority', 'Lower your tone, speak 15% slower, and maintain open body posture', 'Remain completely silent until the guest walks away', 'Immediately retreat to the back office without answering'], 'correct' => 1],
                    ['q' => 'What is the maximum instant amenity voucher a Front Desk Host may authorize without GM signoff?', 'options' => ['₱500 Dining Credit', '₱2,500 F&B or Spa Voucher + Category Upgrade', 'Free Weekend Stay Voucher', '₱10,000 Cash Refund'], 'correct' => 1],
                    ['q' => 'During de-escalation, which phrase should ALWAYS be avoided?', 'options' => ['"I understand your frustration and will personally ensure this is resolved."', '"That is strictly against our hotel policy and there is nothing I can do."', '"Allow me to check what alternatives I can arrange right away."', '"Thank you for bringing this issue to our attention immediately."'], 'correct' => 1],
                    ['q' => 'When handling a room cleanliness complaint, what is the immediate first action?', 'options' => ['Blame the housekeeping contractor on duty', 'Validate the guest distress and offer an immediate room relocation inspection', 'Offer a discount voucher for the next stay next year', 'Request the guest to clean the surface themselves'], 'correct' => 1],
                    ['q' => 'In service recovery, what does "closing the loop" require?', 'options' => ['Archiving the incident ticket quietly', 'Personal follow-up call within 30 minutes to confirm guest satisfaction', 'Reporting the guest name to hotel security blacklist', 'Forwarding the bill to corporate without notes'], 'correct' => 1],
                    ['q' => 'How should a front desk associate handle an intoxicated and disruptive guest in the public foyer?', 'options' => ['Engage in a heated argument in front of other guests', 'Guide the guest respectfully to a private area and notify Duty Manager/Security', 'Refuse all service loudly across the counter', 'Physically push the guest out of the lobby'], 'correct' => 1],
                    ['q' => 'What documentation is mandatory within 60 minutes of resolving a Tier-1 guest incident?', 'options' => ['A personal diary entry', 'A formal Incident Recovery Log entry in the PMS guest profile', 'An anonymous message on social media', 'No record is needed once the guest smiles'], 'correct' => 1]
                ]
            ],
            [
                'id' => 'prog-2',
                'title' => 'HACCP Level 3 Food Safety & Culinary Hygiene',
                'category' => 'Mandatory Compliance',
                'categoryType' => 'compliance',
                'dept' => 'Kitchen',
                'targetCompetency' => 'Food Safety Compliance & Kitchen Sanitation',
                'competencyKey' => 'food_safety_hygiene',
                'duration' => '4.0 Hours (Interactive Cohort)',
                'format' => 'Cohort Workshop & Kitchen Lab',
                'trainerType' => 'Certified Master Trainer',
                'passingScore' => 8,
                'xpAward' => 200,
                'icon' => 'fa-utensils',
                'badgeColor' => 'emerald',
                'description' => 'Critical control point monitoring, cross-contamination prevention, and cold-chain logging.',
                'modules' => ['1. CCP Identification', '2. Temperature Control Logs', '3. Allergen Protocols', '4. Sanitization Evaluation'],
                'quizQuestions' => [
                    ['q' => 'What is the maximum allowable temperature for walk-in chillers in culinary operations?', 'options' => ['4°C (40°F) or below', '10°C (50°F)', '15°C (60°F)', '0°C (32°F)'], 'correct' => 0],
                    ['q' => 'How often must sanitizer concentration test strips be logged per shift?', 'options' => ['Every 2 hours', 'Once per week', 'Only during annual audits', 'At the end of the month'], 'correct' => 0],
                    ['q' => 'What is the temperature "danger zone" where bacterial pathogen growth multiplies rapidly?', 'options' => ['-18°C to 0°C', '5°C to 60°C (41°F to 140°F)', '65°C to 80°C', '85°C to 100°C'], 'correct' => 1],
                    ['q' => 'Which cutting board color is strictly designated for raw poultry in professional kitchens?', 'options' => ['Blue board', 'Yellow board', 'Green board', 'Red board'], 'correct' => 1],
                    ['q' => 'What is the minimum core cooking temperature for poultry and minced meats to ensure safety?', 'options' => ['50°C for 2 minutes', '75°C (165°F) for at least 15 seconds', '60°C for 5 seconds', '45°C instant flash'], 'correct' => 1],
                    ['q' => 'According to FIFO food rotation standards, where should newly delivered produce be stored?', 'options' => ['In front of the older inventory for immediate use', 'Behind older inventory so existing stock is used first', 'Any available empty shelf', 'On the floor near the kitchen receiving bay'], 'correct' => 1],
                    ['q' => 'What is the mandatory corrective action if a walk-in cold room logs 9°C for over 3 hours?', 'options' => ['Ignore it if the produce still smells acceptable', 'Quarantine items, notify Head Chef/Safety Officer, and inspect food safety integrity', 'Reset the thermometer without reporting', 'Immediately serve the food before it spoils further'], 'correct' => 1],
                    ['q' => 'To avoid allergen cross-contact, culinary staff must:', 'options' => ['Rinse knives in cold water without soap between tasks', 'Use dedicated allergen-free cookware, utensils, and clean sanitized gloves', 'Wipe down the prep table with a dry cloth only', 'Cook allergen items on the same grill at higher heat'], 'correct' => 1],
                    ['q' => 'How should frozen meats be safely defrosted according to HACCP guidelines?', 'options' => ['Under direct hot running tap water on the sink', 'In the chiller at ≤4°C or via approved continuous microwave defrost protocol', 'Left overnight on the kitchen counter at ambient room temperature', 'Submerged in stagnant warm water bowls'], 'correct' => 1],
                    ['q' => 'How long should culinary staff scrub hands with antibacterial soap during proper handwashing?', 'options' => ['3 to 5 seconds', 'At least 20 seconds thoroughly covering palms, fingers, and wrists', 'Quick rinse without soap if wearing gloves', '60 seconds only before end of shift'], 'correct' => 1]
                ]
            ],
            [
                'id' => 'prog-3',
                'title' => 'Sommelier Fine Wine Pairing & Vintage Storytelling',
                'category' => 'Revenue & Upsell',
                'categoryType' => 'skill_gap',
                'dept' => 'Food & Beverage',
                'targetCompetency' => 'F&B Product Knowledge & Premium Beverage Storytelling',
                'competencyKey' => 'sommelier_wine_service',
                'duration' => '3.0 Hours (Tasting Workshop)',
                'format' => 'Tasting Workshop & Tableside Service',
                'trainerType' => 'Head Sommelier',
                'passingScore' => 8,
                'xpAward' => 150,
                'icon' => 'fa-wine-glass',
                'badgeColor' => 'purple',
                'description' => 'Old World vs New World wine sensory profiling, varietal characteristics, and degustation pairings.',
                'modules' => ['1. Varietal Sensory Profiling', '2. Degustation Menu Pairing', '3. Tableside Decanting', '4. Evaluation Tasting'],
                'quizQuestions' => [
                    ['q' => 'Which wine pairing is recommended for Prime Dry-Aged Ribeye steak?', 'options' => ['Full-bodied Cabernet Sauvignon', 'Sweet Moscato', 'Light Pinot Grigio', 'Prosecco Extra Dry'], 'correct' => 0],
                    ['q' => 'What is the standard serving temperature for vintage Bordeaux red wines?', 'options' => ['16°C - 18°C (60°F - 65°F)', '4°C - 6°C (39°F - 43°F)', '25°C - 28°C (77°F - 82°F)', '0°C (32°F)'], 'correct' => 0],
                    ['q' => 'What is the standard tableside tasting pour volume presented to the host?', 'options' => ['10 ml (sample sip)', '30 ml to 45 ml (1 to 1.5 oz)', '90 ml (half glass)', '150 ml (full glass)'], 'correct' => 1],
                    ['q' => 'During wine bottle presentation and pouring, where should the front label face?', 'options' => ['Facing downwards toward the floor', 'Facing directly toward the guest being served', 'Turned away and wrapped completely in linen', 'Facing the sommelier chest'], 'correct' => 1],
                    ['q' => 'Why is decanting recommended for mature vintage red wines with heavy sediment?', 'options' => ['To cool the wine down below freezing rapidly', 'To separate clear wine from natural sediment and allow gentle aeration', 'To dilute the alcohol content with room humidity', 'To artificially add bubbles to the wine'], 'correct' => 1],
                    ['q' => 'Which grape varietal is the dominant component of Left Bank Bordeaux blends?', 'options' => ['Chardonnay', 'Cabernet Sauvignon', 'Riesling', 'Sauvignon Blanc'], 'correct' => 1],
                    ['q' => 'What is the ideal wine pairing for delicate Chilean Sea Bass with lemon herb beurre blanc?', 'options' => ['Heavy peppery Shiraz', 'Crisp high-acidity Chablis or oaked Chardonnay', 'Sweet Ruby Port', 'Tannic Barolo'], 'correct' => 1],
                    ['q' => 'What does "terroir" refer to in fine wine storytelling?', 'options' => ['The brand name of the crystal decanter', 'The holistic regional combination of soil, topography, microclimate, and vine heritage', 'The speed at which the wine is shipped', 'The percentage of artificial preservatives added'], 'correct' => 1],
                    ['q' => 'When opening Champagne or Sparkling Wine tableside, how should the cork be released?', 'options' => ['Pointing towards guest faces with a loud loud pop', 'Hold the cork firmly, gently twist the bottle base for a quiet controlled hiss', 'Strike the bottle neck violently with a butter knife', 'Shake the bottle vigorously before removing wire cage'], 'correct' => 1],
                    ['q' => 'When pairing dessert courses such as Dark Chocolate Lava Cake, the wine should generally be:', 'options' => ['Significantly more sour and acidic than the dessert', 'Slightly sweeter and richer than the dessert itself (e.g. Vintage Port or Banyuls)', 'Ice cold sparkling water with lemon only', 'Bone-dry Sauvignon Blanc with low alcohol'], 'correct' => 1]
                ]
            ]
        ];
    }
}
