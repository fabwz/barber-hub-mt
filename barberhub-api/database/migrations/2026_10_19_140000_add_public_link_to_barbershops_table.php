<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;

return new class extends Migration
{
    /** Add the public link: first nullable, then filled in the existing rows, then required and unique (RF-02, MT-07). */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('public_link', 120)->nullable();
        });

        $this->fillExistingRows();

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('public_link', 120)->nullable(false)->change();
            $table->unique('public_link', 'barbershops_public_link_unique');
        });

        DB::statement("alter table barbershops add constraint barbershops_public_link_format_check check (public_link ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
    }

    /** Remove the public link with its key and its check. */
    public function down(): void
    {
        DB::statement('alter table barbershops drop constraint barbershops_public_link_format_check');

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropUnique('barbershops_public_link_unique');
            $table->dropColumn('public_link');
        });
    }

    /** Give each existing barbershop the link its name would have got, the oldest one first, so homonyms are numbered in order. */
    private function fillExistingRows(): void
    {
        $generator = new PublicLinkGenerator;
        $taken = [];

        foreach (DB::table('barbershops')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $row) {
            $name = new BarbershopName($row->name);

            for ($number = 1; ; $number++) {
                $link = $generator->candidate($name, $number)->value;

                if (! isset($taken[$link])) {
                    break;
                }
            }

            $taken[$link] = true;
            DB::table('barbershops')->where('id', $row->id)->update(['public_link' => $link]);
        }
    }
};
