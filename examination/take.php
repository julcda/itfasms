<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/exam_questionnaire.php';

require_login();

$connection = db();
$user = current_user();
$questions = entrance_exam_questions();
$totalQuestions = count($questions);
$passingScore = 60;

if ($totalQuestions !== 25) {
    flash_set('error', 'Entrance examination must contain exactly 25 items.');
    redirect_to(app_url('examination/index.php'));
}

$examId = to_int($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if ($examId <= 0) {
    flash_set('error', 'Invalid examination session.');
    redirect_to(app_url('examination/index.php'));
}

$examStmt = $connection->prepare(
    'SELECT e.exam_id, e.student_id, e.exam_date, e.exam_score, e.Payment_Status, e.Date_Result, e.Remarks, e.Status,
            p.lrn, p.surname, p.firstname, p.middlename, p.department, p.contact, p.sex
     FROM entranceexamination e
     INNER JOIN preregistration p ON p.id = e.student_id
     WHERE e.exam_id = ?
     LIMIT 1'
);
$examStmt->bind_param('i', $examId);
$examStmt->execute();
$examRow = $examStmt->get_result()->fetch_assoc();

if (!$examRow) {
    flash_set('error', 'Scheduled examination record was not found.');
    redirect_to(app_url('examination/index.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(app_url('examination/take.php?exam_id=' . (string) $examId));
    }

    $answers = [];
    $itemScores = [];
    $totalScore = 0;

    foreach ($questions as $question) {
        $qid = (int) $question['id'];
        $posted = strtoupper(trim((string) ($_POST['q' . $qid] ?? '')));
        if ($posted === '' || !isset($question['choices'][$posted])) {
            flash_set('error', 'Please answer all questionnaire items before submitting.');
            redirect_to(app_url('examination/take.php?exam_id=' . (string) $examId));
        }

        $isCorrect = hash_equals((string) $question['answer'], $posted);
        $score = $isCorrect ? 4 : 0;
        $answers[$qid] = $posted;
        $itemScores[$qid] = $score;
        $totalScore += $score;
    }

    $remarks = $totalScore >= $passingScore ? 'Passed' : 'Failed';
    $status = 'For Enrollment';
    $resultDate = date('Y-m-d');

    try {
        $checkScoreStmt = $connection->prepare('SELECT exam_id FROM entranceexam_score WHERE exam_id = ? LIMIT 1');
        $checkScoreStmt->bind_param('i', $examId);
        $checkScoreStmt->execute();
        $scoreExists = (bool) $checkScoreStmt->get_result()->fetch_assoc();

        $scores = [];
        for ($i = 1; $i <= 25; $i++) {
            $scores[] = (int) ($itemScores[$i] ?? 0);
        }

        if ($scoreExists) {
            $updateScoreSql = 'UPDATE entranceexam_score SET '
                . 'student_id=?, date_of_exam=?, '
                . 'item1_score=?, item2_score=?, item3_score=?, item4_score=?, item5_score=?, '
                . 'item6_score=?, item7_score=?, item8_score=?, item9_score=?, item10_score=?, '
                . 'item11_score=?, item12_score=?, item13_score=?, item14_score=?, item15_score=?, '
                . 'item16_score=?, item17_score=?, item18_score=?, item19_score=?, item20_score=?, '
                . 'item21_score=?, item22_score=?, item23_score=?, item24_score=?, item25_score=? '
                . 'WHERE exam_id=?';

            $updateScoreStmt = $connection->prepare($updateScoreSql);
            $types = 'is' . str_repeat('i', 25) . 'i';
            $params = array_merge([(int) $examRow['student_id'], $resultDate], $scores, [$examId]);
            bind_dynamic_params($updateScoreStmt, $types, $params);
            $updateScoreStmt->execute();
        } else {
            $insertScoreSql = 'INSERT INTO entranceexam_score '
                . '(exam_id, student_id, date_of_exam, '
                . 'item1_score, item2_score, item3_score, item4_score, item5_score, '
                . 'item6_score, item7_score, item8_score, item9_score, item10_score, '
                . 'item11_score, item12_score, item13_score, item14_score, item15_score, '
                . 'item16_score, item17_score, item18_score, item19_score, item20_score, '
                . 'item21_score, item22_score, item23_score, item24_score, item25_score) '
                . 'VALUES (' . implode(', ', array_fill(0, 28, '?')) . ')';

            $insertScoreStmt = $connection->prepare($insertScoreSql);
            $types = 'iis' . str_repeat('i', 25);
            $params = array_merge([$examId, (int) $examRow['student_id'], $resultDate], $scores);
            bind_dynamic_params($insertScoreStmt, $types, $params);
            $insertScoreStmt->execute();
        }

        $updateExamStmt = $connection->prepare(
            'UPDATE entranceexamination
             SET exam_score = ?, Date_Result = ?, Remarks = ?, Status = ?
             WHERE exam_id = ?'
        );
        $updateExamStmt->bind_param('isssi', $totalScore, $resultDate, $remarks, $status, $examId);
        $updateExamStmt->execute();

        flash_set('success', 'Congratulations! Your examination has been successfully submitted. You may now proceed to the enrollment office.');
        redirect_to(app_url('examination/index.php'));
    } catch (Throwable $error) {
        flash_set('error', 'Failed to save exam result: ' . $error->getMessage());
        redirect_to(app_url('examination/take.php?exam_id=' . (string) $examId));
    }
}

$studentName = trim(((string) $examRow['surname']) . ', ' . ((string) $examRow['firstname']) . ' ' . ((string) $examRow['middlename']));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Take Examination | ITFA Enrollment System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Manrope', 'ui-sans-serif', 'system-ui']
                    },
                    colors: {
                        brand: {
                            50: '#f0f7f2',
                            500: '#2e8b57',
                            600: '#166534',
                            700: '#0f4d28'
                        }
                    },
                    boxShadow: {
                        panel: '0 22px 50px -25px rgba(22,101,52,0.30)'
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.16),_rgba(241,245,249,0.86)_42%,_rgba(241,245,249,1)_75%)]">
    <header class="max-w-5xl mx-auto px-4 pt-8 pb-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-white/90 backdrop-blur p-6 sm:p-8 shadow-panel border border-green-100">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs tracking-[0.2em] uppercase text-brand-700 font-semibold">Entrance Examination</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Questionnaire</h1>
                    <p class="text-slate-500 mt-2">Answer all <?= h((string) $totalQuestions) ?> questions. Each correct answer is worth 4 points.</p>
                    <p class="text-xs text-slate-500 mt-2">Proctor: <?= h((string) ($user['full_name'] ?? 'Staff')) ?></p>
                </div>
                <div class="flex gap-2">
                    <a href="<?= h(app_url('examination/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Back to List</a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 pb-10 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-panel mb-5">
            <h2 class="text-lg font-bold mb-3">Student Details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p class="text-slate-500 text-xs">Name</p>
                    <p class="font-semibold"><?= h($studentName) ?></p>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p class="text-slate-500 text-xs">LRN</p>
                    <p class="font-semibold"><?= h((string) $examRow['lrn']) ?></p>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p class="text-slate-500 text-xs">Department</p>
                    <p class="font-semibold"><?= h((string) $examRow['department']) ?></p>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p class="text-slate-500 text-xs">Exam Date</p>
                    <p class="font-semibold"><?= h((string) $examRow['exam_date']) ?></p>
                </div>
            </div>
            <p class="text-xs text-slate-500 mt-3">Passing score: <?= h((string) $passingScore) ?>/100</p>
        </section>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="exam_id" value="<?= h((string) $examId) ?>">

            <?php foreach ($questions as $question): ?>
                <?php $qid = (int) $question['id']; ?>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-panel">
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <span class="inline-flex rounded-full bg-brand-50 text-brand-700 px-2.5 py-1 text-xs font-semibold">Item <?= h((string) $qid) ?></span>
                        <span class="inline-flex rounded-full bg-slate-100 text-slate-600 px-2.5 py-1 text-xs font-medium"><?= h((string) $question['category']) ?></span>
                    </div>
                    <p class="font-semibold text-slate-900 mb-3"><?= h((string) $question['question']) ?></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php foreach ($question['choices'] as $choiceKey => $choiceText): ?>
                            <label class="flex items-start gap-2 rounded-xl border border-slate-200 p-3 hover:bg-slate-50 cursor-pointer">
                                <input type="radio" required name="q<?= h((string) $qid) ?>" value="<?= h((string) $choiceKey) ?>" class="mt-1 text-brand-600 focus:ring-brand-500">
                                <span class="text-sm"><span class="font-semibold"><?= h((string) $choiceKey) ?>.</span> <?= h((string) $choiceText) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="sticky bottom-4">
                <div class="rounded-2xl border border-green-200 bg-green-50 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <p class="text-sm text-green-800">Make sure all answers are completed before submitting.</p>
                    <button type="submit" class="rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold px-5 py-2.5">Submit Examination</button>
                </div>
            </div>
        </form>
    </main>
</div>
</body>
</html>
