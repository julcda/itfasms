<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GradesController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SoaController;
use App\Http\Controllers\Student;
use App\Http\Controllers\Teacher;
use Illuminate\Support\Facades\Route;

// ── Guest ────────────────────────────────────────────────────────────────────
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

// ── SSO handoff from the native teacher session ──────────────────────────────
Route::get('/sso/teacher/{ticket}', [Teacher\SsoController::class, 'consume'])->name('teacher.sso');

// ── Super Admin maintenance console ──────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [Admin\AuthController::class, 'show'])->name('login');
    Route::post('/login', [Admin\AuthController::class, 'login'])->name('login.attempt');
    Route::match(['get', 'post'], '/logout', [Admin\AuthController::class, 'logout'])->name('logout');

    Route::middleware('admin')->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        // Student portal accounts
        Route::get('/students', [Admin\StudentAccountController::class, 'index'])->name('students');
        Route::post('/students/{id}/reset', [Admin\StudentAccountController::class, 'resetPassword'])->name('students.reset');
        Route::post('/students/{id}/status', [Admin\StudentAccountController::class, 'toggleStatus'])->name('students.status');
        Route::post('/students/bulk-reset', [Admin\StudentAccountController::class, 'bulkReset'])->name('students.bulk-reset');

        // Access monitoring
        Route::get('/monitoring', [Admin\MonitoringController::class, 'index'])->name('monitoring');

        // Backups
        Route::get('/backups', [Admin\BackupController::class, 'index'])->name('backups');
        Route::post('/backups/run', [Admin\BackupController::class, 'run'])->name('backups.run');
        Route::get('/backups/{name}/download', [Admin\BackupController::class, 'download'])->name('backups.download');
        Route::delete('/backups/{name}', [Admin\BackupController::class, 'destroy'])->name('backups.destroy');

        // Maintenance / system
        Route::get('/maintenance', [Admin\MaintenanceController::class, 'index'])->name('maintenance');
        Route::post('/maintenance/clear-cache', [Admin\MaintenanceController::class, 'clearCache'])->name('maintenance.clear-cache');
    });
});

// ── Password change (reachable while must_change_password is set) ─────────────
Route::middleware('student:allow-pw-change')->group(function () {
    Route::get('/password', [PasswordController::class, 'show'])->name('password.change');
    Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
});

// ── Authenticated student portal ─────────────────────────────────────────────
Route::middleware('student')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/soa', [SoaController::class, 'index'])->name('soa');
    Route::get('/soa/print', [SoaController::class, 'print'])->name('soa.print');
    Route::get('/grades', [GradesController::class, 'index'])->name('grades');
    Route::get('/grades/slip', [GradesController::class, 'slip'])->name('grades.slip');
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::post('/account/photo', [AccountController::class, 'uploadPhoto'])->name('account.photo');
    Route::get('/certificates', [CertificateController::class, 'index'])->name('certificates');
    Route::get('/certificates/{id}/print', [CertificateController::class, 'print'])->name('certificates.print');
    Route::get('/notifications', [Student\NotificationController::class, 'index'])->name('student.notifications.index');
    Route::post('/notifications/read', [Student\NotificationController::class, 'markRead'])->name('student.notifications.read');

    // ── Classroom (LMS) — student side ───────────────────────────────────────
    Route::get('/classes', [Student\ClassroomController::class, 'index'])->name('student.classes.index');
    Route::get('/classes/{classId}', [Student\ClassroomController::class, 'show'])->name('student.classes.show');
    Route::get('/classes/{classId}/assignments', [Student\AssignmentController::class, 'classIndex'])->name('student.classes.assignments');
    Route::get('/classes/{classId}/quizzes', [Student\QuizController::class, 'classIndex'])->name('student.classes.quizzes');
    Route::get('/classes/{classId}/announcements', [Student\AnnouncementController::class, 'classIndex'])->name('student.classes.announcements');
    Route::get('/classes/{classId}/discussion', [Student\DiscussionController::class, 'index'])->name('student.classes.discussion');

    Route::get('/lessons/{lessonId}', [Student\ClassroomController::class, 'lesson'])->name('student.lessons.show');
    Route::post('/lessons/{lessonId}/complete', [Student\ClassroomController::class, 'markComplete'])->name('student.lessons.complete');
    Route::post('/lessons/{lessonId}/viewed', [Student\ClassroomController::class, 'markViewed'])->name('student.lessons.viewed');

    Route::get('/assignments/{id}', [Student\AssignmentController::class, 'show'])->name('student.assignments.show');
    Route::post('/assignments/{id}/submit', [Student\AssignmentController::class, 'submit'])->name('student.assignments.submit');

    Route::get('/quizzes/{id}', [Student\QuizController::class, 'show'])->name('student.quizzes.show');
    Route::post('/quizzes/{id}/start', [Student\QuizController::class, 'start'])->name('student.quizzes.start');
    Route::get('/attempts/{attemptId}', [Student\QuizController::class, 'take'])->name('student.attempts.take');
    Route::post('/attempts/{attemptId}/submit', [Student\QuizController::class, 'submit'])->name('student.attempts.submit');
    Route::get('/attempts/{attemptId}/result', [Student\QuizController::class, 'result'])->name('student.attempts.result');

    Route::post('/discussion/{classId}/threads', [Student\DiscussionController::class, 'storeThread'])->name('student.discussions.threads.store');
    Route::post('/threads/{threadId}/replies', [Student\DiscussionController::class, 'reply'])->name('student.discussions.reply');
    Route::post('/replies/{replyId}/like', [Student\DiscussionController::class, 'like'])->name('student.discussions.like');
});

// ── Authenticated teacher Classroom (reached only via SSO) ───────────────────
Route::middleware('teacher')->prefix('teacher')->name('teacher.')->group(function () {
    Route::get('/', [Teacher\DashboardController::class, 'index'])->name('dashboard');

    // Workspace tabs
    Route::get('/classes/{classId}', [Teacher\ClassWorkspaceController::class, 'stream'])->name('classes.stream');
    Route::get('/classes/{classId}/students', [Teacher\ClassWorkspaceController::class, 'students'])->name('classes.students');
    Route::get('/classes/{classId}/analytics', [Teacher\AnalyticsController::class, 'index'])->name('analytics.index');

    // Lessons + materials
    Route::get('/classes/{classId}/lessons', [Teacher\LessonController::class, 'index'])->name('lessons.index');
    Route::get('/classes/{classId}/materials', [Teacher\LessonController::class, 'materials'])->name('materials.index');
    Route::get('/classes/{classId}/lessons/create', [Teacher\LessonController::class, 'create'])->name('lessons.create');
    Route::post('/classes/{classId}/lessons', [Teacher\LessonController::class, 'store'])->name('lessons.store');
    Route::post('/classes/{classId}/lessons/reorder', [Teacher\LessonController::class, 'reorder'])->name('lessons.reorder');
    Route::get('/lessons/{lessonId}/edit', [Teacher\LessonController::class, 'edit'])->name('lessons.edit');
    Route::patch('/lessons/{lessonId}', [Teacher\LessonController::class, 'update'])->name('lessons.update');
    Route::post('/lessons/{lessonId}/publish', [Teacher\LessonController::class, 'publish'])->name('lessons.publish');
    Route::post('/lessons/{lessonId}/unpublish', [Teacher\LessonController::class, 'unpublish'])->name('lessons.unpublish');
    Route::delete('/lessons/{lessonId}', [Teacher\LessonController::class, 'destroy'])->name('lessons.destroy');
    Route::post('/lessons/{lessonId}/resources', [Teacher\LessonResourceController::class, 'store'])->name('resources.store');
    Route::post('/lessons/{lessonId}/resources/reorder', [Teacher\LessonResourceController::class, 'reorder'])->name('resources.reorder');
    Route::delete('/resources/{resourceId}', [Teacher\LessonResourceController::class, 'destroy'])->name('resources.destroy');

    // Assignments
    Route::get('/classes/{classId}/assignments', [Teacher\AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/classes/{classId}/assignments/create', [Teacher\AssignmentController::class, 'create'])->name('assignments.create');
    Route::post('/classes/{classId}/assignments', [Teacher\AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/assignments/{id}/edit', [Teacher\AssignmentController::class, 'edit'])->name('assignments.edit');
    Route::patch('/assignments/{id}', [Teacher\AssignmentController::class, 'update'])->name('assignments.update');
    Route::post('/assignments/{id}/publish', [Teacher\AssignmentController::class, 'publish'])->name('assignments.publish');
    Route::post('/assignments/{id}/unpublish', [Teacher\AssignmentController::class, 'unpublish'])->name('assignments.unpublish');
    Route::delete('/assignments/{id}', [Teacher\AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::delete('/assignment-attachments/{attachmentId}', [Teacher\AssignmentController::class, 'destroyAttachment'])->name('assignments.attachments.destroy');
    Route::get('/assignments/{id}/submissions', [Teacher\AssignmentController::class, 'submissions'])->name('assignments.submissions');
    Route::post('/submissions/{submissionId}/grade', [Teacher\AssignmentController::class, 'gradeSubmission'])->name('assignments.submissions.grade');

    // Quizzes
    Route::get('/classes/{classId}/quizzes', [Teacher\QuizController::class, 'index'])->name('quizzes.index');
    Route::get('/classes/{classId}/quizzes/create', [Teacher\QuizController::class, 'create'])->name('quizzes.create');
    Route::post('/classes/{classId}/quizzes', [Teacher\QuizController::class, 'store'])->name('quizzes.store');
    Route::get('/quizzes/{id}/edit', [Teacher\QuizController::class, 'edit'])->name('quizzes.edit');
    Route::patch('/quizzes/{id}', [Teacher\QuizController::class, 'update'])->name('quizzes.update');
    Route::post('/quizzes/{id}/publish', [Teacher\QuizController::class, 'publish'])->name('quizzes.publish');
    Route::post('/quizzes/{id}/unpublish', [Teacher\QuizController::class, 'unpublish'])->name('quizzes.unpublish');
    Route::delete('/quizzes/{id}', [Teacher\QuizController::class, 'destroy'])->name('quizzes.destroy');
    Route::post('/quizzes/{id}/questions', [Teacher\QuizController::class, 'storeQuestion'])->name('quizzes.questions.store');
    Route::delete('/questions/{questionId}', [Teacher\QuizController::class, 'destroyQuestion'])->name('quizzes.questions.destroy');
    Route::get('/quizzes/{id}/results', [Teacher\QuizController::class, 'results'])->name('quizzes.results');
    Route::get('/attempts/{attemptId}/review', [Teacher\QuizController::class, 'review'])->name('quizzes.attempts.review');
    Route::post('/answers/{answerId}/grade', [Teacher\QuizController::class, 'gradeAnswer'])->name('quizzes.answers.grade');

    // Announcements
    Route::get('/classes/{classId}/announcements', [Teacher\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/classes/{classId}/announcements', [Teacher\AnnouncementController::class, 'store'])->name('announcements.store');
    Route::delete('/announcements/{id}', [Teacher\AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    // Discussion
    Route::get('/classes/{classId}/discussion', [Teacher\DiscussionController::class, 'index'])->name('discussions.index');
    Route::post('/classes/{classId}/discussion/threads', [Teacher\DiscussionController::class, 'storeThread'])->name('discussions.threads.store');
    Route::post('/threads/{threadId}/replies', [Teacher\DiscussionController::class, 'reply'])->name('discussions.reply');
    Route::post('/threads/{threadId}/pin', [Teacher\DiscussionController::class, 'togglePin'])->name('discussions.pin');
    Route::delete('/threads/{threadId}', [Teacher\DiscussionController::class, 'destroyThread'])->name('discussions.threads.destroy');
    Route::delete('/discussion-replies/{replyId}', [Teacher\DiscussionController::class, 'destroyReply'])->name('discussions.replies.destroy');
    Route::post('/discussion-replies/{replyId}/like', [Teacher\DiscussionController::class, 'like'])->name('discussions.like');

    // Gradebook (grade integration)
    Route::get('/classes/{classId}/gradebook', [Teacher\GradebookController::class, 'index'])->name('gradebook.index');

    Route::get('/notifications', [Teacher\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [Teacher\NotificationController::class, 'markRead'])->name('notifications.read');
});
