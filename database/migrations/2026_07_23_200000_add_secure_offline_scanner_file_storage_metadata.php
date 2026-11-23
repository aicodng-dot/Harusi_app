<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_scanner_packages', function (Blueprint $table): void {
            $table->string('storage_disk', 80)->default('offline_scanner')->after('file_path');
            $table->string('internal_format', 120)->default('harusi.offline-scanner.package')->after('storage_disk');
            $table->unsignedBigInteger('downloaded_by_user_id')->nullable()->after('downloaded_at');
            $table->timestamp('archived_at')->nullable()->after('revoked_at');
            $table->unsignedBigInteger('archived_by_user_id')->nullable()->after('archived_at');
            $table->timestamp('secure_deleted_at')->nullable()->after('archived_by_user_id');
            $table->unsignedBigInteger('secure_deleted_by_user_id')->nullable()->after('secure_deleted_at');
            $table->text('secure_deletion_reason')->nullable()->after('secure_deleted_by_user_id');

            $table->foreign('downloaded_by_user_id', 'os_pkg_download_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('archived_by_user_id', 'os_pkg_archive_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('secure_deleted_by_user_id', 'os_pkg_delete_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->timestamp('uploaded_at')->nullable()->after('uploaded_by_user_id');
            $table->string('storage_disk', 80)->default('offline_scanner')->after('encrypted_file_path');
            $table->string('internal_format', 120)->default('harusi.offline-scanner.results')->after('storage_disk');
            $table->timestamp('verified_at')->nullable()->after('verification_error');
            $table->timestamp('archived_at')->nullable()->after('imported_at');
            $table->unsignedBigInteger('archived_by_user_id')->nullable()->after('archived_at');
            $table->timestamp('secure_deleted_at')->nullable()->after('archived_by_user_id');
            $table->unsignedBigInteger('secure_deleted_by_user_id')->nullable()->after('secure_deleted_at');
            $table->text('secure_deletion_reason')->nullable()->after('secure_deleted_by_user_id');

            $table->foreign('archived_by_user_id', 'os_ri_archive_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('secure_deleted_by_user_id', 'os_ri_delete_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->dropForeign('os_ri_archive_user_fk');
            $table->dropForeign('os_ri_delete_user_fk');
            $table->dropColumn([
                'uploaded_at',
                'storage_disk',
                'internal_format',
                'verified_at',
                'archived_at',
                'archived_by_user_id',
                'secure_deleted_at',
                'secure_deleted_by_user_id',
                'secure_deletion_reason',
            ]);
        });

        Schema::table('offline_scanner_packages', function (Blueprint $table): void {
            $table->dropForeign('os_pkg_download_user_fk');
            $table->dropForeign('os_pkg_archive_user_fk');
            $table->dropForeign('os_pkg_delete_user_fk');
            $table->dropColumn([
                'storage_disk',
                'internal_format',
                'downloaded_by_user_id',
                'archived_at',
                'archived_by_user_id',
                'secure_deleted_at',
                'secure_deleted_by_user_id',
                'secure_deletion_reason',
            ]);
        });
    }
};