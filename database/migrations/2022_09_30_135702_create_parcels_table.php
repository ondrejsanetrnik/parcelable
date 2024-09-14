<?php

use App\Order;
use App\Purchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateParcelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('parcels')) {
            Schema::create('parcels', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
                $table->nullableMorphs('parcelable');
                $table->string('type', 16)->default('parcel');
                $table->string('carrier', 16);
                $table->string('tracking_number', 16)->index();
                $table->string('password', 16)->nullable();
                $table->string('status', 32)->nullable();
                $table->string('name', 128)->nullable();
                $table->date('stored_until')->nullable();
                $table->unsignedSmallInteger('cod')->nullable();

                $table->unique(['carrier', 'tracking_number']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('parcels');
    }
}
