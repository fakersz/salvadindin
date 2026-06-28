--
-- PostgreSQL database dump
--

\restrict j2PBMbTxBs5b8nnCuVnY7IDcg1ivPdDbT0i1KU8twJnMa3qUbAIYxOa1EWIdjKS

-- Dumped from database version 16.14 (Ubuntu 16.14-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.14 (Ubuntu 16.14-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: billing_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_records (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    description character varying(160) NOT NULL,
    amount numeric(12,2) NOT NULL,
    due_date date NOT NULL,
    paid_at date,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT billing_records_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT billing_records_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('paid'::character varying)::text, ('canceled'::character varying)::text])))
);


--
-- Name: billing_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.billing_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: billing_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.billing_records_id_seq OWNED BY public.billing_records.id;


--
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(100) NOT NULL,
    type character varying(10) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT categories_type_check CHECK (((type)::text = ANY (ARRAY[('income'::character varying)::text, ('expense'::character varying)::text])))
);


--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.categories_id_seq OWNED BY public.categories.id;


--
-- Name: debts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.debts (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    title character varying(140) NOT NULL,
    amount numeric(12,2) NOT NULL,
    debt_type character varying(12) NOT NULL,
    due_date date NOT NULL,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    paid_at date,
    installment_number integer DEFAULT 1 NOT NULL,
    installments_total integer DEFAULT 1 NOT NULL,
    notes character varying(180),
    financing_total numeric(12,2),
    financing_start_date date,
    financing_end_date date,
    financing_due_day integer,
    late_fee_rate numeric(6,3) DEFAULT 0 NOT NULL,
    monthly_interest_rate numeric(6,3) DEFAULT 0 NOT NULL,
    debt_group_key character varying(64),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT debts_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT debts_debt_type_check CHECK (((debt_type)::text = ANY (ARRAY[('monthly'::character varying)::text, ('installment'::character varying)::text, ('single'::character varying)::text]))),
    CONSTRAINT debts_financing_due_day_check CHECK (((financing_due_day IS NULL) OR ((financing_due_day >= 1) AND (financing_due_day <= 31)))),
    CONSTRAINT debts_installment_number_check CHECK ((installment_number > 0)),
    CONSTRAINT debts_installments_total_check CHECK ((installments_total > 0)),
    CONSTRAINT debts_late_fee_rate_check CHECK ((late_fee_rate >= (0)::numeric)),
    CONSTRAINT debts_monthly_interest_rate_check CHECK ((monthly_interest_rate >= (0)::numeric)),
    CONSTRAINT debts_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('paid'::character varying)::text])))
);


--
-- Name: debts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.debts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: debts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.debts_id_seq OWNED BY public.debts.id;


--
-- Name: goals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.goals (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    title character varying(140) NOT NULL,
    target_amount numeric(12,2) NOT NULL,
    current_amount numeric(12,2) DEFAULT 0 NOT NULL,
    deadline date,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT goals_current_amount_check CHECK ((current_amount >= (0)::numeric)),
    CONSTRAINT goals_target_amount_check CHECK ((target_amount > (0)::numeric))
);


--
-- Name: goals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.goals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: goals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.goals_id_seq OWNED BY public.goals.id;


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transactions (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    category_id bigint,
    title character varying(140) NOT NULL,
    amount numeric(12,2) NOT NULL,
    transaction_type character varying(10) NOT NULL,
    transaction_date date NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    payment_method character varying(16) DEFAULT 'pix_cash'::character varying NOT NULL,
    is_essential boolean DEFAULT false NOT NULL,
    CONSTRAINT transactions_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT transactions_payment_method_check CHECK (((payment_method)::text = ANY (ARRAY[('pix_cash'::character varying)::text, ('debit'::character varying)::text, ('credit'::character varying)::text, ('transfer'::character varying)::text, ('other'::character varying)::text]))),
    CONSTRAINT transactions_transaction_type_check CHECK (((transaction_type)::text = ANY (ARRAY[('income'::character varying)::text, ('expense'::character varying)::text])))
);


--
-- Name: transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transactions_id_seq OWNED BY public.transactions.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(120) NOT NULL,
    email character varying(190) NOT NULL,
    password character varying(255) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    is_admin boolean DEFAULT false NOT NULL,
    account_status character varying(12) DEFAULT 'active'::character varying NOT NULL,
    plan_name character varying(80) DEFAULT 'free'::character varying NOT NULL,
    plan_price numeric(12,2) DEFAULT 0 NOT NULL,
    plan_expires_at date,
    last_online_at timestamp with time zone,
    last_payment_at date,
    admin_notes text,
    CONSTRAINT users_account_status_check CHECK (((account_status)::text = ANY (ARRAY[('active'::character varying)::text, ('blocked'::character varying)::text, ('expired'::character varying)::text])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: billing_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_records ALTER COLUMN id SET DEFAULT nextval('public.billing_records_id_seq'::regclass);


--
-- Name: categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories ALTER COLUMN id SET DEFAULT nextval('public.categories_id_seq'::regclass);


--
-- Name: debts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debts ALTER COLUMN id SET DEFAULT nextval('public.debts_id_seq'::regclass);


--
-- Name: goals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.goals ALTER COLUMN id SET DEFAULT nextval('public.goals_id_seq'::regclass);


--
-- Name: transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions ALTER COLUMN id SET DEFAULT nextval('public.transactions_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: billing_records billing_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_records
    ADD CONSTRAINT billing_records_pkey PRIMARY KEY (id);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: categories categories_user_name_type_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_user_name_type_unique UNIQUE (user_id, name, type);


--
-- Name: debts debts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debts
    ADD CONSTRAINT debts_pkey PRIMARY KEY (id);


--
-- Name: goals goals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.goals
    ADD CONSTRAINT goals_pkey PRIMARY KEY (id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: idx_billing_records_status_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_billing_records_status_due ON public.billing_records USING btree (status, due_date);


--
-- Name: idx_billing_records_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_billing_records_user_id ON public.billing_records USING btree (user_id);


--
-- Name: idx_categories_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_categories_user_id ON public.categories USING btree (user_id);


--
-- Name: idx_debts_user_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_debts_user_due ON public.debts USING btree (user_id, due_date);


--
-- Name: idx_debts_user_group_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_debts_user_group_key ON public.debts USING btree (user_id, debt_group_key);


--
-- Name: idx_debts_user_type_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_debts_user_type_status ON public.debts USING btree (user_id, debt_type, status);


--
-- Name: idx_goals_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_goals_user_id ON public.goals USING btree (user_id);


--
-- Name: idx_transactions_category_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_transactions_category_id ON public.transactions USING btree (category_id);


--
-- Name: idx_transactions_user_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_transactions_user_date ON public.transactions USING btree (user_id, transaction_date DESC);


--
-- Name: idx_transactions_user_essential; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_transactions_user_essential ON public.transactions USING btree (user_id, is_essential);


--
-- Name: idx_transactions_user_payment_method; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_transactions_user_payment_method ON public.transactions USING btree (user_id, payment_method);


--
-- Name: idx_transactions_user_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_transactions_user_type ON public.transactions USING btree (user_id, transaction_type);


--
-- Name: idx_users_last_online_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_last_online_at ON public.users USING btree (last_online_at DESC);


--
-- Name: billing_records billing_records_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_records
    ADD CONSTRAINT billing_records_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: categories categories_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: debts debts_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debts
    ADD CONSTRAINT debts_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: goals goals_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.goals
    ADD CONSTRAINT goals_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: transactions transactions_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE SET NULL;


--
-- Name: transactions transactions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict j2PBMbTxBs5b8nnCuVnY7IDcg1ivPdDbT0i1KU8twJnMa3qUbAIYxOa1EWIdjKS
