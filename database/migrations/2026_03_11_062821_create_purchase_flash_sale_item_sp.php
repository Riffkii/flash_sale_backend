<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION purchase_flash_sale_item(
                p_user_id integer,
                p_item_id integer
            ) RETURNS integer AS $$
            DECLARE
                v_result integer := 0;
                v_price numeric(18,2);
            BEGIN
                SELECT price INTO v_price
                FROM flash_sale_items
                WHERE id = p_item_id;

                UPDATE flash_sale_items
                SET stock = stock - 1
                WHERE id = p_item_id AND stock > 0;

                IF FOUND THEN
                    INSERT INTO flash_sale_orders(user_id, item_id, price, created_at)
                    VALUES (p_user_id, p_item_id, v_price, NOW());

                    v_result := 1;
                END IF;

                RETURN v_result;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS purchase_flash_sale_item(BIGINT, BIGINT);');
    }
};