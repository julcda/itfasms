<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LMS module schema — additive only, touches no existing table.
 *
 * FK columns that point at LEGACY tables are deliberately typed `integer()`
 * (signed INT(11)) to match those tables' primary keys exactly — legacy PKs
 * predate Laravel and are signed int, not unsigned bigint, so a mismatched
 * type would either fail the FK constraint or silently skip the index.
 * FK columns between NEW classroom_* tables use Laravel's normal unsigned bigint
 * convention since both sides are under this migration's control.
 *
 * No `classroom_classes` table: a class in the school sense already exists as
 * `classes` (teacher + subject + section + school year). Duplicating it here
 * would create exactly the two-sources-of-truth problem this module is meant
 * to avoid for grades. Every classroom_* table that scopes to "a class" stores
 * `class_id` referencing `classes.Class_id` directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Lessons ──────────────────────────────────────────────────────────
        Schema::create('classroom_lessons', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');                 // -> classes.Class_id
            $table->integer('grading_period_id')->nullable(); // -> grading_period.id ("Quarter")
            $table->unsignedSmallInteger('week_number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('topic')->nullable();
            $table->text('learning_competency')->nullable();
            $table->text('objectives')->nullable();
            $table->text('instructions')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->integer('created_by');                // -> user_account.user_id
            $table->timestamps();

            $table->index(['class_id', 'status']);
            $table->index(['class_id', 'sort_order']);
        });

        // ── Lesson resources (video/document/image/link) ────────────────────
        Schema::create('classroom_lesson_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('classroom_lessons')->cascadeOnDelete();
            $table->enum('type', ['video_upload', 'video_youtube', 'video_vimeo', 'video_gdrive', 'document', 'image', 'link']);
            $table->string('title');
            $table->string('url')->nullable();            // embeds / external links
            $table->string('file_path')->nullable();      // uploads (relative to shared uploads dir)
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['lesson_id', 'sort_order']);
        });

        // ── Per-student lesson progress ──────────────────────────────────────
        Schema::create('classroom_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('classroom_lessons')->cascadeOnDelete();
            $table->integer('student_id');                // -> studentinfo.student_id
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->dateTime('last_viewed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'student_id']);
        });

        // ── Assignments ───────────────────────────────────────────────────────
        Schema::create('classroom_assignments', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');
            $table->foreignId('lesson_id')->nullable()->constrained('classroom_lessons')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->unsignedSmallInteger('max_score')->default(100);
            $table->boolean('allow_late')->default(true);
            $table->enum('submission_mode', ['individual', 'group'])->default('individual');
            $table->boolean('require_file')->default(true);
            $table->boolean('require_text')->default(false);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['class_id', 'status']);
        });

        Schema::create('classroom_assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('classroom_assignments')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        Schema::create('classroom_assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('classroom_assignments')->cascadeOnDelete();
            $table->integer('student_id');
            $table->text('text_answer')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->enum('status', ['draft', 'submitted', 'late', 'missing', 'returned', 'graded'])->default('draft');
            $table->decimal('score', 6, 2)->nullable();
            $table->text('teacher_comment')->nullable();
            $table->integer('graded_by')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'student_id'], 'cas_assignment_student_unique');
        });

        Schema::create('classroom_assignment_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('classroom_assignment_submissions')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        // ── Quizzes ───────────────────────────────────────────────────────────
        Schema::create('classroom_quizzes', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');
            $table->foreignId('lesson_id')->nullable()->constrained('classroom_lessons')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->decimal('passing_score', 6, 2)->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_choices')->default(false);
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->boolean('show_score_immediately')->default(true);
            $table->boolean('show_correct_answers')->default(false);
            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->boolean('auto_submit')->default(true);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['class_id', 'status']);
        });

        Schema::create('classroom_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('classroom_quizzes')->cascadeOnDelete();
            $table->enum('type', [
                'mcq', 'multi_select', 'true_false', 'identification',
                'short_answer', 'essay', 'matching', 'ordering', 'fill_blank',
            ]);
            $table->text('question_text');
            $table->decimal('points', 6, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            // Type-specific config (matching pairs, ordering sequence, fill-blank
            // answer key, etc.) — kept as JSON rather than N extra tables, since
            // shape varies per question type and none of it is queried directly.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['quiz_id', 'sort_order']);
        });

        Schema::create('classroom_quiz_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('classroom_quiz_questions')->cascadeOnDelete();
            $table->string('choice_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('match_key')->nullable(); // pairs a matching-type choice to its prompt
            $table->timestamps();

            $table->index(['question_id', 'sort_order']);
        });

        Schema::create('classroom_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('classroom_quizzes')->cascadeOnDelete();
            $table->integer('student_id');
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->dateTime('started_at');
            $table->dateTime('submitted_at')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('is_auto_submitted')->default(false);
            $table->enum('status', ['in_progress', 'submitted', 'graded'])->default('in_progress');
            $table->timestamps();

            $table->index(['quiz_id', 'student_id']);
        });

        Schema::create('classroom_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('classroom_quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('classroom_quiz_questions')->cascadeOnDelete();
            $table->text('answer_text')->nullable();       // short answer / identification / essay / fill-blank
            $table->json('selected_choice_ids')->nullable(); // mcq / multi-select / ordering / matching
            $table->boolean('is_correct')->nullable();      // null until graded (essay)
            $table->decimal('points_awarded', 6, 2)->nullable();
            $table->text('teacher_feedback')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        // ── Announcements ─────────────────────────────────────────────────────
        Schema::create('classroom_announcements', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');
            $table->string('title')->nullable();
            $table->text('body');
            $table->integer('created_by');
            $table->timestamps();

            $table->index('class_id');
        });

        Schema::create('classroom_announcement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('classroom_announcements')->cascadeOnDelete();
            $table->enum('type', ['file', 'image', 'link']);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        // ── Discussion board (class-wide or per-lesson) ─────────────────────
        Schema::create('classroom_discussion_threads', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');
            $table->foreignId('lesson_id')->nullable()->constrained('classroom_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('image_path')->nullable();
            // No unified `users` table exists — teacher and student are separate
            // legacy tables with separate PKs, so authorship is a role+id pair.
            $table->enum('author_role', ['teacher', 'student']);
            $table->integer('author_id');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['class_id', 'is_pinned']);
            $table->index('lesson_id');
        });

        Schema::create('classroom_discussion_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('classroom_discussion_threads')->cascadeOnDelete();
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->enum('author_role', ['teacher', 'student']);
            $table->integer('author_id');
            $table->timestamps();

            $table->index('thread_id');
        });

        Schema::create('classroom_discussion_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_id')->constrained('classroom_discussion_replies')->cascadeOnDelete();
            $table->enum('author_role', ['teacher', 'student']);
            $table->integer('author_id');
            $table->timestamps();

            $table->unique(['reply_id', 'author_role', 'author_id'], 'cdl_reply_author_unique');
        });

        // ── Notifications (event + per-recipient delivery/read state) ──────
        Schema::create('classroom_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');            // e.g. 'lesson_published','assignment_graded'
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable(); // route/URL the notification opens
            $table->json('data')->nullable();   // structured payload (lesson_id, etc.)
            $table->integer('class_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('type');
            $table->index('class_id');
        });

        Schema::create('classroom_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('classroom_notifications')->cascadeOnDelete();
            $table->enum('recipient_role', ['teacher', 'student']);
            $table->integer('recipient_id');
            $table->dateTime('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_role', 'recipient_id', 'read_at'], 'cnr_recipient_read_idx');
        });

        // ── Grade integration (staging layer — never writes student_grade directly) ─
        Schema::create('classroom_grade_integration', function (Blueprint $table) {
            $table->id();
            $table->integer('class_id');
            $table->integer('student_id');
            $table->integer('grading_period_id');
            $table->enum('source_type', ['assignment', 'quiz', 'performance_task']);
            $table->unsignedBigInteger('source_id'); // classroom_assignments.id or classroom_quizzes.id
            $table->decimal('score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->decimal('weight', 5, 2)->nullable(); // reserved for future weighted averaging
            $table->boolean('synced_to_student_grade')->default(false);
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'student_id'], 'cgi_source_student_unique');
            $table->index(['class_id', 'student_id', 'grading_period_id'], 'cgi_class_student_period_idx');
        });

        // ── SSO handoff tickets (native teacher session -> this Laravel app) ──
        // One-time, short-lived tickets issued by the native teacher_auth.php,
        // consumed here to establish a Teacher session with no second login.
        Schema::create('classroom_sso_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket', 64)->unique();
            $table->integer('teacher_id');           // -> teacher.Teacher_id
            $table->string('redirect_path')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_sso_tickets');
        Schema::dropIfExists('classroom_grade_integration');
        Schema::dropIfExists('classroom_notification_reads');
        Schema::dropIfExists('classroom_notifications');
        Schema::dropIfExists('classroom_discussion_likes');
        Schema::dropIfExists('classroom_discussion_replies');
        Schema::dropIfExists('classroom_discussion_threads');
        Schema::dropIfExists('classroom_announcement_attachments');
        Schema::dropIfExists('classroom_announcements');
        Schema::dropIfExists('classroom_quiz_answers');
        Schema::dropIfExists('classroom_quiz_attempts');
        Schema::dropIfExists('classroom_quiz_choices');
        Schema::dropIfExists('classroom_quiz_questions');
        Schema::dropIfExists('classroom_quizzes');
        Schema::dropIfExists('classroom_assignment_submission_files');
        Schema::dropIfExists('classroom_assignment_submissions');
        Schema::dropIfExists('classroom_assignment_attachments');
        Schema::dropIfExists('classroom_assignments');
        Schema::dropIfExists('classroom_lesson_progress');
        Schema::dropIfExists('classroom_lesson_resources');
        Schema::dropIfExists('classroom_lessons');
    }
};
