BEGIN;

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS plan_name character varying(80),
    ADD COLUMN IF NOT EXISTS plan_price numeric(12,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS plan_expires_at date,
    ADD COLUMN IF NOT EXISTS last_online_at timestamp with time zone;

UPDATE public.users
SET plan_name = 'free'
WHERE plan_name IS NULL OR btrim(plan_name) = '';

UPDATE public.users
SET plan_price = 0
WHERE plan_price IS NULL;

ALTER TABLE public.users
    ALTER COLUMN plan_name SET DEFAULT 'free',
    ALTER COLUMN plan_name SET NOT NULL,
    ALTER COLUMN plan_price SET DEFAULT 0,
    ALTER COLUMN plan_price SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_users_last_online_at
    ON public.users (last_online_at DESC);

ALTER TABLE public.users
    DROP CONSTRAINT IF EXISTS users_access_key_id_fkey,
    DROP COLUMN IF EXISTS access_key_id;

DROP TABLE IF EXISTS public.access_keys CASCADE;
DROP SEQUENCE IF EXISTS public.access_keys_id_seq;

COMMIT;
