<?php

use App\Support\MongoId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpieza de redundancias del esquema (refactor de unificación):
 *
 * 1. `daily_totals` era el segundo registro del mismo grain
 *    (usuario×servicio×día×cantidad) que `entries`; los dos módulos podían
 *    contar el mismo ingreso y divergir. Se unifica todo en `entries`
 *    (tipo INGRESO) y se elimina la tabla.
 * 2. `positions` (histórico de cargos con texto libre atado a users) se
 *    reemplaza por `puestos` + `empleado_puestos` (catálogos normalizados
 *    atados a empleados). Se eliminan también las columnas herederas
 *    `users.activePositionId` y `users.title` (el título ahora vive en
 *    `tratamientos` vía el empleado).
 * 3. `password_reset_tokens` (sin flujo de reset en la app) y `sessions`
 *    (la app autentica solo con Bearer tokens) nunca se usaban.
 * 4. Integridad: el grain de `entries` que `updateOrCreate` asume
 *    (usuario×servicio×día) gana su índice único correspondiente.
 *
 * El `down()` reconstruye las tablas vacías (pérdida de datos documentada).
 * En SQLite (tests) las columnas legacy se eliminan sin dropForeign.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // (1) daily_totals → entries (defensivo: hoy vacía; solo inserta lo que falte).
        if (Schema::hasTable('daily_totals') && Schema::hasTable('entries')) {
            foreach (DB::table('daily_totals')->orderBy('date')->get() as $row) {
                $exists = DB::table('entries')
                    ->where('userId', $row->userId)
                    ->where('serviceId', $row->serviceId)
                    ->where('date', $row->date)
                    ->exists();

                if (! $exists) {
                    DB::table('entries')->insert([
                        'id' => MongoId::generate(),
                        'userId' => $row->userId,
                        'serviceId' => $row->serviceId,
                        'date' => $row->date,
                        'type' => 'INGRESO',
                        'quantity' => $row->quantity,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }
            }
        }

        Schema::disableForeignKeyConstraints();

        // (2) Columnas legacy de users.
        if (Schema::hasColumn('users', 'activePositionId')) {
            if ($driver === 'mysql') {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropForeign(['activePositionId']);
                });
            }
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('activePositionId');
            });
        }
        if (Schema::hasColumn('users', 'title')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }

        // (1)+(2)+(3) Tablas eliminadas.
        Schema::dropIfExists('daily_totals');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');

        Schema::enableForeignKeyConstraints();

        // (4) Índice único del grain de entries (con nombre explícito para poder
        // eliminarlo en down() sin adivinar el nombre generado).
        if (Schema::hasTable('entries')) {
            Schema::table('entries', function (Blueprint $table) {
                $table->unique(['userId', 'serviceId', 'date'], 'entries_user_service_day_unique');
            });
        }
    }

    public function down(): void
    {
        // Reconstrucción vacía de lo eliminado (los datos no se recuperan).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->char('user_id', 24)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('username', 50)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('userId', 24);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->string('title', 8)->default('NONE');
            $table->string('position', 191);
            $table->string('department', 191)->nullable();
            $table->boolean('isActive')->default(true);
            $table->dateTime('startDate');
            $table->dateTime('endDate')->nullable();
            $table->timestamps();

            $table->index(['userId', 'isActive']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('title', 8)->default('NONE')->after('role');
            $table->char('activePositionId', 24)->nullable();
            $table->foreign('activePositionId')->references('id')->on('positions')->nullOnDelete();
        });

        Schema::create('daily_totals', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('userId', 24);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->char('serviceId', 24);
            $table->foreign('serviceId')->references('id')->on('services')->cascadeOnDelete();
            $table->dateTime('date')->index();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->index(['userId', 'date']);
            $table->index(['date', 'serviceId']);
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->dropUnique('entries_user_service_day_unique');
        });
    }
};
