<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Size guides become admin-managed and chosen per product. A guide is a
 * title, a note, a list of column headings (after the Size column) and rows
 * of [size, ...values]. The table that used to be hard-coded on the product
 * page is seeded as the default guide and assigned to every existing product,
 * so nothing changes on the shop until an admin edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_guides', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('note')->nullable();
            $table->json('columns');
            $table->json('rows');
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('size_guide_id')->nullable()->constrained('size_guides')->nullOnDelete();
        });

        $now = now();
        $guideId = DB::table('size_guides')->insertGetId([
            'name' => 'Standard apparel',
            'note' => 'Measurements are in centimetres. If between sizes, size up for a relaxed fit.',
            'columns' => json_encode(['Chest (cm)', 'Waist (cm)', 'Hips (cm)'], JSON_UNESCAPED_UNICODE),
            'rows' => json_encode([
                ['S', '86–91', '71–76', '91–96'],
                ['M', '91–97', '76–81', '96–102'],
                ['L', '97–102', '81–86', '102–107'],
                ['XL', '107–112', '91–97', '112–117'],
                ['XXL', '117–122', '97–107', '122–127'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('products')->update(['size_guide_id' => $guideId]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('size_guide_id');
        });

        Schema::dropIfExists('size_guides');
    }
};
