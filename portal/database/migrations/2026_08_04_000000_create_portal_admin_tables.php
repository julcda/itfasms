<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin maintenance tables for the student portal.
 *
 *  - portal_login_audit : every student login/logout/failed attempt, so the
 *    Super Admin can monitor who is accessing the portal and when.
 *  - portal_admin_audit : a trail of every maintenance action a Super Admin
 *    performs (password resets, deactivations, backups) — there is no trusted
 *    path; sensitive actions are always recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('portal_login_audit')) {
            Schema::create('portal_login_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('enrollment_id')->nullable();
                $table->string('lrn', 40)->nullable()->index();
                $table->enum('event', ['login', 'logout', 'failed'])->default('login');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (!Schema::hasTable('portal_admin_audit')) {
            Schema::create('portal_admin_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('admin_name', 150)->nullable();
                $table->string('action', 80);
                $table->string('target', 190)->nullable();
                $table->text('details')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_login_audit');
        Schema::dropIfExists('portal_admin_audit');
    }
};
