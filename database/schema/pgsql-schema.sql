--
-- PostgreSQL database dump
--

\restrict FHgUEsYPHj0hQmGTEagNNx8RxECaAxW6LGvRzHXhDbOOBcN7KJ0NtHI6EUHF6aZ

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

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

--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: EXTENSION btree_gist; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION btree_gist IS 'support for indexing common datatypes in GiST';


--
-- Name: customer_account_non_overdraft_entry_trigger_fn(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.customer_account_non_overdraft_entry_trigger_fn() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    line RECORD;
BEGIN
    IF NEW.status = 'posted' THEN
        FOR line IN
            SELECT DISTINCT customer_account_id, currency
            FROM journal_lines
            WHERE journal_entry_id = NEW.id
              AND customer_account_id IS NOT NULL
        LOOP
            PERFORM enforce_customer_account_non_overdraft(line.customer_account_id, line.currency);
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: customer_account_non_overdraft_line_trigger_fn(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.customer_account_non_overdraft_line_trigger_fn() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM enforce_customer_account_non_overdraft(OLD.customer_account_id, OLD.currency);
        RETURN OLD;
    END IF;

    PERFORM enforce_customer_account_non_overdraft(NEW.customer_account_id, NEW.currency);
    IF TG_OP = 'UPDATE' AND (OLD.customer_account_id IS DISTINCT FROM NEW.customer_account_id OR OLD.currency IS DISTINCT FROM NEW.currency) THEN
        PERFORM enforce_customer_account_non_overdraft(OLD.customer_account_id, OLD.currency);
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_customer_account_non_overdraft(bigint, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_customer_account_non_overdraft(target_account_id bigint, target_currency text) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    balance_minor BIGINT;
    allows_overdraft BOOLEAN;
    overdraft_limit BIGINT;
BEGIN
    IF target_account_id IS NULL OR target_currency IS NULL THEN
        RETURN;
    END IF;

    SELECT COALESCE(ap.allows_overdraft, FALSE), COALESCE(ap.overdraft_limit_minor, 0)
      INTO allows_overdraft, overdraft_limit
      FROM customer_accounts ca
      LEFT JOIN account_products ap ON ap.id = ca.account_product_id
      WHERE ca.id = target_account_id;

    SELECT COALESCE(SUM(
        CASE
            WHEN la.normal_balance_side = 'debit' THEN jl.debit_minor - jl.credit_minor
            ELSE jl.credit_minor - jl.debit_minor
        END
    ), 0)
      INTO balance_minor
      FROM journal_lines jl
      JOIN journal_entries je ON je.id = jl.journal_entry_id
      JOIN ledger_accounts la ON la.id = jl.ledger_account_id
      WHERE je.status = 'posted'
        AND jl.customer_account_id = target_account_id
        AND jl.currency = target_currency;

    IF allows_overdraft IS NOT TRUE AND balance_minor < 0 THEN
        RAISE EXCEPTION 'Customer account % would be overdrawn: balance=%', target_account_id, balance_minor
          USING ERRCODE = '23514';
    END IF;

    IF allows_overdraft IS TRUE AND balance_minor < (0 - overdraft_limit) THEN
        RAISE EXCEPTION 'Customer account % overdraft limit exceeded: balance=% limit=%', target_account_id, balance_minor, overdraft_limit
          USING ERRCODE = '23514';
    END IF;
END;
$$;


--
-- Name: enforce_journal_entry_balance(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_journal_entry_balance(target_entry_id bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    entry_status TEXT;
    debit_total BIGINT;
    credit_total BIGINT;
BEGIN
    SELECT status INTO entry_status FROM journal_entries WHERE id = target_entry_id;
    IF entry_status IS NULL OR entry_status IN ('draft', 'cancelled', 'rejected') THEN
        RETURN;
    END IF;
    SELECT COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
      INTO debit_total, credit_total
      FROM journal_lines
      WHERE journal_entry_id = target_entry_id;
    IF debit_total <> credit_total THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced: debit=% credit=%', target_entry_id, debit_total, credit_total
          USING ERRCODE = '23514';
    END IF;
END;
$$;


--
-- Name: enforce_journal_entry_status_transitions(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_journal_entry_status_transitions() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.status = NEW.status THEN
        RETURN NEW;
    END IF;
    IF OLD.status = 'draft' AND NEW.status NOT IN ('submitted', 'cancelled', 'archived') THEN
        RAISE EXCEPTION 'Draft journal entries can only be submitted, cancelled, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'submitted' AND NEW.status NOT IN ('approved', 'rejected', 'cancelled', 'archived') THEN
        RAISE EXCEPTION 'Submitted journal entries can only be approved, rejected, cancelled, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'posted' AND NEW.status <> 'reversed' THEN
        RAISE EXCEPTION 'Posted journal entries can only transition to reversed (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'reversed' THEN
        RAISE EXCEPTION 'Reversed journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'cancelled' THEN
        RAISE EXCEPTION 'Cancelled journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'archived' THEN
        RAISE EXCEPTION 'Archived journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'rejected' AND NEW.status NOT IN ('cancelled', 'archived', 'draft') THEN
        RAISE EXCEPTION 'Rejected journal entries can only be cancelled, archived, or reworked to draft (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'approved' AND NEW.status NOT IN ('posted', 'rejected', 'archived') THEN
        RAISE EXCEPTION 'Approved journal entries can only be posted, rejected, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: enforce_journal_line_immutability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_journal_line_immutability() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    parent_status TEXT;
    target_entry_id BIGINT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        target_entry_id := OLD.journal_entry_id;
    ELSE
        target_entry_id := NEW.journal_entry_id;
    END IF;
    SELECT status INTO parent_status FROM journal_entries WHERE id = target_entry_id;
    IF parent_status <> 'draft' AND (
        TG_OP <> 'INSERT'
        OR EXISTS (
            SELECT 1 FROM journal_lines
            WHERE journal_entry_id = target_entry_id
            AND id <> COALESCE(NEW.id, -1)
            LIMIT 1
        )
    ) THEN
        RAISE EXCEPTION 'Journal lines under % entries are immutable (entry %)', parent_status, target_entry_id
          USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: enforce_single_currency_journal_lines(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_single_currency_journal_lines() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    first_currency TEXT;
BEGIN
    SELECT currency INTO first_currency
      FROM journal_lines
      WHERE journal_entry_id = NEW.journal_entry_id
        AND id <> COALESCE(NEW.id, -1)
      ORDER BY id
      LIMIT 1;

    IF first_currency IS NOT NULL AND first_currency <> NEW.currency THEN
        RAISE EXCEPTION 'Journal entry % already uses currency %, cannot add %', NEW.journal_entry_id, first_currency, NEW.currency
          USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: journal_entries_balance_trigger_fn(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.journal_entries_balance_trigger_fn() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    PERFORM enforce_journal_entry_balance(NEW.id);
    RETURN NEW;
END;
$$;


--
-- Name: journal_lines_balance_trigger_fn(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.journal_lines_balance_trigger_fn() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    target_id BIGINT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        target_id := OLD.journal_entry_id;
    ELSE
        target_id := NEW.journal_entry_id;
    END IF;
    PERFORM enforce_journal_entry_balance(target_id);
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: account_holds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.account_holds (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    customer_account_id bigint NOT NULL,
    amount_minor bigint NOT NULL,
    currency character varying(3) NOT NULL,
    reason_type character varying(64) NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    placed_at timestamp(0) without time zone,
    placed_by_user_id bigint,
    released_at timestamp(0) without time zone,
    released_by_user_id bigint,
    reference character varying(128),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    source_type character varying(64),
    source_public_id character varying(64),
    expires_at timestamp(0) without time zone,
    release_reason character varying(255),
    CONSTRAINT account_holds_amount_positive CHECK ((amount_minor > 0))
);


--
-- Name: account_holds_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.account_holds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: account_holds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.account_holds_id_seq OWNED BY public.account_holds.id;


--
-- Name: account_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.account_products (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint,
    ledger_account_id bigint,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    account_family character varying(64) NOT NULL,
    minimum_balance_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    allows_recovery_debit boolean DEFAULT false NOT NULL,
    is_recovery_account boolean DEFAULT false NOT NULL,
    is_ordinary_savings boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    allows_overdraft boolean DEFAULT false NOT NULL,
    overdraft_limit_minor bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT account_products_min_balance_non_negative CHECK ((minimum_balance_minor >= 0))
);


--
-- Name: account_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.account_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: account_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.account_products_id_seq OWNED BY public.account_products.id;


--
-- Name: activity_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_log (
    id bigint NOT NULL,
    log_name character varying(255),
    description text NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    event character varying(255),
    causer_type character varying(255),
    causer_id bigint,
    attribute_changes json,
    properties json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_log_id_seq OWNED BY public.activity_log.id;


--
-- Name: agencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agencies (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(32) NOT NULL,
    name character varying(255) NOT NULL,
    region character varying(128),
    city character varying(128),
    branch_name character varying(128),
    phone_number character varying(32),
    email character varying(255),
    address_line_1 character varying(255),
    address_line_2 character varying(255),
    creation_date date,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    manager_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    branch_type character varying(64),
    po_box character varying(128),
    fax_number character varying(32),
    geographic_description text
);


--
-- Name: agencies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.agencies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: agencies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.agencies_id_seq OWNED BY public.agencies.id;


--
-- Name: api_idempotency_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_idempotency_keys (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    method character varying(10) NOT NULL,
    path character varying(512) NOT NULL,
    actor_context character varying(512) NOT NULL,
    scope_hash character varying(64) NOT NULL,
    request_fingerprint character varying(64) NOT NULL,
    response_body json,
    response_status smallint,
    response_headers json,
    completed_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: api_idempotency_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_idempotency_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_idempotency_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_idempotency_keys_id_seq OWNED BY public.api_idempotency_keys.id;


--
-- Name: batch_procedures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.batch_procedures (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    schedule_type character varying(32),
    schedule_metadata json,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: batch_procedures_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.batch_procedures_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: batch_procedures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.batch_procedures_id_seq OWNED BY public.batch_procedures.id;


--
-- Name: batch_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.batch_runs (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    batch_procedure_id bigint NOT NULL,
    agency_id bigint,
    business_date date NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    operator_user_id bigint,
    idempotency_key character varying(128),
    summary_payload json,
    failure_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    request_fingerprint character varying(64),
    actor_context character varying(512),
    scope_hash character varying(64)
);


--
-- Name: batch_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.batch_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: batch_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.batch_runs_id_seq OWNED BY public.batch_runs.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: client_guarantors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_guarantors (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    guarantor_client_id bigint,
    guarantor_full_name character varying(255),
    guarantor_phone_number character varying(32),
    relationship_type character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    starts_on date,
    ends_on date,
    verification_status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    document_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    submitted_at timestamp(0) without time zone,
    verified_at timestamp(0) without time zone,
    verified_by_user_id bigint,
    rejected_at timestamp(0) without time zone,
    rejection_reason text,
    created_by_user_id bigint,
    archived_at timestamp(0) without time zone,
    CONSTRAINT client_guarantors_not_self CHECK (((guarantor_client_id IS NULL) OR (guarantor_client_id <> client_id)))
);


--
-- Name: client_guarantors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_guarantors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_guarantors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_guarantors_id_seq OWNED BY public.client_guarantors.id;


--
-- Name: client_identity_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_identity_documents (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    document_type character varying(64) NOT NULL,
    document_number text NOT NULL,
    issuing_authority text,
    issued_on date,
    expires_on date,
    verification_status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    verified_at timestamp(0) without time zone,
    verified_by_user_id bigint,
    document_id bigint,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    submitted_at timestamp(0) without time zone,
    rejected_at timestamp(0) without time zone,
    rejection_reason text,
    created_by_user_id bigint,
    archived_at timestamp(0) without time zone,
    document_number_hash character varying(64)
);


--
-- Name: client_identity_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_identity_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_identity_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_identity_documents_id_seq OWNED BY public.client_identity_documents.id;


--
-- Name: client_kyc_reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_kyc_reviews (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    previous_kyc_status character varying(32) NOT NULL,
    new_kyc_status character varying(32) NOT NULL,
    reason text,
    comment text,
    acted_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: client_kyc_reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_kyc_reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_kyc_reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_kyc_reviews_id_seq OWNED BY public.client_kyc_reviews.id;


--
-- Name: client_notification_consents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_notification_consents (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    channel character varying(32) NOT NULL,
    category character varying(64) NOT NULL,
    language character varying(8) DEFAULT 'fr'::character varying NOT NULL,
    status character varying(16) DEFAULT 'opted_in'::character varying NOT NULL,
    opted_in_at timestamp(0) without time zone,
    opted_out_at timestamp(0) without time zone,
    last_changed_by_user_id bigint,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT client_notification_consents_status_valid CHECK (((status)::text = ANY ((ARRAY['opted_in'::character varying, 'opted_out'::character varying])::text[])))
);


--
-- Name: client_notification_consents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_notification_consents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_notification_consents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_notification_consents_id_seq OWNED BY public.client_notification_consents.id;


--
-- Name: client_proxies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_proxies (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    proxy_full_name character varying(255) NOT NULL,
    proxy_phone_number character varying(32),
    proxy_email character varying(255),
    proxy_id_document_type character varying(64),
    proxy_id_document_number character varying(128),
    mandate_type character varying(64) NOT NULL,
    starts_on date,
    ends_on date,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    document_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    verification_status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    submitted_at timestamp(0) without time zone,
    verified_at timestamp(0) without time zone,
    verified_by_user_id bigint,
    rejected_at timestamp(0) without time zone,
    rejection_reason text,
    created_by_user_id bigint,
    archived_at timestamp(0) without time zone,
    customer_account_id bigint,
    operation_types json,
    max_amount_minor bigint,
    limit_currency character varying(3),
    CONSTRAINT client_proxies_limit_currency_required CHECK (((max_amount_minor IS NULL) OR (limit_currency IS NOT NULL)))
);


--
-- Name: client_proxies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_proxies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_proxies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_proxies_id_seq OWNED BY public.client_proxies.id;


--
-- Name: clients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clients (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    client_reference character varying(64) NOT NULL,
    first_name character varying(128) NOT NULL,
    last_name character varying(128) NOT NULL,
    middle_name character varying(128),
    date_of_birth date,
    place_of_birth character varying(255),
    gender character varying(32),
    phone_number character varying(32),
    email character varying(255),
    address_line_1 character varying(255),
    address_line_2 character varying(255),
    city character varying(128),
    region character varying(128),
    occupation character varying(128),
    employer_name character varying(255),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    kyc_status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    onboarded_on date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    prospector_id bigint,
    collection_type character varying(64),
    collection_frequency character varying(32),
    collection_target_amount numeric(18,2),
    collection_agent_id bigint,
    kyc_submitted_at timestamp(0) without time zone,
    kyc_verified_at timestamp(0) without time zone,
    kyc_verified_by_user_id bigint,
    kyc_rejected_at timestamp(0) without time zone,
    kyc_rejection_reason text,
    kyc_suspended_at timestamp(0) without time zone,
    kyc_archived_at timestamp(0) without time zone,
    profile_photo_document_id bigint,
    father_name character varying(128),
    mother_name character varying(128),
    home_phone_number character varying(32),
    business_started_on date,
    business_activity_started_on date,
    business_address_line_1 character varying(255),
    business_address_line_2 character varying(255),
    business_city character varying(128),
    business_region character varying(128),
    kyc_submitted_by_user_id bigint,
    sector_id bigint,
    sub_sector_id bigint,
    CONSTRAINT clients_collection_target_non_negative CHECK (((collection_target_amount IS NULL) OR (collection_target_amount >= (0)::numeric))),
    CONSTRAINT clients_kyc_status_allowed CHECK (((kyc_status)::text = ANY ((ARRAY['draft'::character varying, 'pending_review'::character varying, 'verified'::character varying, 'rejected'::character varying, 'suspended'::character varying, 'archived'::character varying])::text[]))),
    CONSTRAINT clients_sub_sector_requires_sector CHECK (((sub_sector_id IS NULL) OR (sector_id IS NOT NULL)))
);


--
-- Name: clients_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.clients_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: clients_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.clients_id_seq OWNED BY public.clients.id;


--
-- Name: collateral_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.collateral_items (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    collateral_id bigint NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    description character varying(255) NOT NULL,
    reference character varying(128),
    chassis_number character varying(128),
    registration_number character varying(128),
    amount_minor bigint,
    currency character varying(3),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT collateral_items_amount_positive CHECK (((amount_minor IS NULL) OR (amount_minor > 0)))
);


--
-- Name: collateral_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.collateral_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: collateral_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.collateral_items_id_seq OWNED BY public.collateral_items.id;


--
-- Name: collaterals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.collaterals (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint,
    loan_id bigint,
    collateral_type character varying(64) NOT NULL,
    description text,
    owner_full_name character varying(255),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    valuation_date date,
    declared_value_minor bigint,
    currency character varying(3),
    document_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    CONSTRAINT collaterals_declared_value_positive CHECK (((declared_value_minor IS NULL) OR (declared_value_minor > 0)))
);


--
-- Name: collaterals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.collaterals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: collaterals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.collaterals_id_seq OWNED BY public.collaterals.id;


--
-- Name: currencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.currencies (
    id bigint NOT NULL,
    code character varying(3) NOT NULL,
    name character varying(255) NOT NULL,
    minor_unit smallint DEFAULT '2'::smallint NOT NULL,
    is_base_currency boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT currencies_minor_unit_valid CHECK (((minor_unit >= 0) AND (minor_unit <= 8))),
    CONSTRAINT currencies_status_valid CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: currencies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.currencies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: currencies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.currencies_id_seq OWNED BY public.currencies.id;


--
-- Name: customer_account_signatures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_account_signatures (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    customer_account_id bigint NOT NULL,
    client_id bigint NOT NULL,
    document_id bigint NOT NULL,
    client_proxy_id bigint,
    signature_type character varying(32) NOT NULL,
    signer_name character varying(255),
    signer_role character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    captured_on date,
    verified_at timestamp(0) without time zone,
    verified_by_user_id bigint,
    revoked_at timestamp(0) without time zone,
    revoked_by_user_id bigint,
    revocation_reason character varying(255),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT customer_account_signatures_revoked_fields_check CHECK (((((status)::text <> 'revoked'::text) AND (revoked_at IS NULL)) OR (((status)::text = 'revoked'::text) AND (revoked_at IS NOT NULL)))),
    CONSTRAINT customer_account_signatures_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'superseded'::character varying, 'revoked'::character varying, 'archived'::character varying])::text[]))),
    CONSTRAINT customer_account_signatures_type_check CHECK (((signature_type)::text = ANY ((ARRAY['primary_holder'::character varying, 'joint_holder'::character varying, 'proxy'::character varying, 'mandate'::character varying, 'thumbprint'::character varying])::text[])))
);


--
-- Name: customer_account_signatures_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_account_signatures_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_account_signatures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_account_signatures_id_seq OWNED BY public.customer_account_signatures.id;


--
-- Name: customer_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_accounts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    ledger_account_id bigint,
    account_number character varying(64) NOT NULL,
    account_type character varying(64),
    opened_on date NOT NULL,
    closed_on date,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    account_product_id bigint,
    manager_user_id bigint,
    account_title character varying(255),
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    unavailable_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    signature_path character varying(255),
    CONSTRAINT customer_accounts_unavailable_non_negative CHECK ((unavailable_amount_minor >= 0))
);


--
-- Name: customer_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_accounts_id_seq OWNED BY public.customer_accounts.id;


--
-- Name: dashboard_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_definitions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    audience character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    layout json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dashboard_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dashboard_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dashboard_definitions_id_seq OWNED BY public.dashboard_definitions.id;


--
-- Name: dashboard_widgets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_widgets (
    id bigint NOT NULL,
    dashboard_definition_id bigint NOT NULL,
    code character varying(64) NOT NULL,
    title character varying(255) NOT NULL,
    widget_type character varying(64) NOT NULL,
    "position" smallint DEFAULT '0'::smallint NOT NULL,
    configuration json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dashboard_widgets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dashboard_widgets_id_seq OWNED BY public.dashboard_widgets.id;


--
-- Name: delinquency_trackings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.delinquency_trackings (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    loan_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    tracking_date date NOT NULL,
    reason_code character varying(64),
    appointment_type character varying(64),
    appointment_date date,
    promised_amount_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    comments text,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: delinquency_trackings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.delinquency_trackings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: delinquency_trackings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.delinquency_trackings_id_seq OWNED BY public.delinquency_trackings.id;


--
-- Name: denominations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.denominations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(32) NOT NULL,
    label character varying(64) NOT NULL,
    value_minor bigint NOT NULL,
    currency character varying(3) NOT NULL,
    type character varying(32) DEFAULT 'banknote'::character varying NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT denominations_value_positive CHECK ((value_minor > 0))
);


--
-- Name: denominations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.denominations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: denominations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.denominations_id_seq OWNED BY public.denominations.id;


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    owner_type character varying(255),
    owner_id bigint,
    uploaded_by_user_id bigint,
    category character varying(64) NOT NULL,
    title character varying(255) NOT NULL,
    disk character varying(255),
    path character varying(255),
    original_name character varying(255),
    mime_type character varying(128),
    size_bytes bigint,
    checksum_sha256 character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    verified_at timestamp(0) without time zone,
    verified_by_user_id bigint,
    archived_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL
);


--
-- Name: documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.documents_id_seq OWNED BY public.documents.id;


--
-- Name: emf_ledger_account_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emf_ledger_account_mappings (
    id bigint NOT NULL,
    emf_regulatory_account_id bigint NOT NULL,
    ledger_account_id bigint NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    public_id character(26) NOT NULL
);


--
-- Name: emf_ledger_account_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.emf_ledger_account_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: emf_ledger_account_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.emf_ledger_account_mappings_id_seq OWNED BY public.emf_ledger_account_mappings.id;


--
-- Name: emf_regulatory_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emf_regulatory_accounts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    account_class character varying(32),
    parent_emf_regulatory_account_id bigint,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    regulatory_source_id bigint
);


--
-- Name: emf_regulatory_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.emf_regulatory_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: emf_regulatory_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.emf_regulatory_accounts_id_seq OWNED BY public.emf_regulatory_accounts.id;


--
-- Name: exchange_rates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exchange_rates (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    base_currency character varying(3) NOT NULL,
    quote_currency character varying(3) NOT NULL,
    reference_rate numeric(20,8) NOT NULL,
    buy_margin_rate numeric(12,6) DEFAULT '0'::numeric NOT NULL,
    sell_margin_rate numeric(12,6) DEFAULT '0'::numeric NOT NULL,
    buy_rate numeric(20,8) NOT NULL,
    sell_rate numeric(20,8) NOT NULL,
    effective_on date NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    effective_to date,
    CONSTRAINT exchange_rates_pair_scope_valid CHECK ((((base_currency)::text = 'XAF'::text) AND ((quote_currency)::text <> 'XAF'::text))),
    CONSTRAINT exchange_rates_positive CHECK (((reference_rate > (0)::numeric) AND (buy_rate > (0)::numeric) AND (sell_rate > (0)::numeric))),
    CONSTRAINT exchange_rates_status_valid CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'superseded'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: exchange_rates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.exchange_rates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: exchange_rates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.exchange_rates_id_seq OWNED BY public.exchange_rates.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: fx_authorizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_authorizations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint,
    authorization_reference character varying(191) NOT NULL,
    authorization_type character varying(32) NOT NULL,
    effective_from date NOT NULL,
    effective_to date,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    supports_purchase boolean DEFAULT true NOT NULL,
    supports_sale boolean DEFAULT true NOT NULL,
    metadata json,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT fx_authorizations_dates_valid CHECK (((effective_to IS NULL) OR (effective_to >= effective_from))),
    CONSTRAINT fx_authorizations_status_valid CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'suspended'::character varying, 'revoked'::character varying])::text[]))),
    CONSTRAINT fx_authorizations_type_valid CHECK (((authorization_type)::text = ANY ((ARRAY['credit_institution'::character varying, 'emf'::character varying, 'postal_administration'::character varying, 'dedicated_bureau'::character varying, 'sub_delegate'::character varying])::text[])))
);


--
-- Name: fx_authorizations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_authorizations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_authorizations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_authorizations_id_seq OWNED BY public.fx_authorizations.id;


--
-- Name: fx_reconciliations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_reconciliations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    till_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    business_date date NOT NULL,
    currency character varying(3) NOT NULL,
    counted_minor bigint NOT NULL,
    theoretical_minor bigint NOT NULL,
    variance_minor bigint NOT NULL,
    status character varying(32) DEFAULT 'open'::character varying NOT NULL,
    notes text,
    closed_by_user_id bigint,
    closed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT fx_reconciliations_counted_non_negative CHECK (((counted_minor >= 0) AND (theoretical_minor >= 0))),
    CONSTRAINT fx_reconciliations_status_valid CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'closed'::character varying, 'variance_blocked'::character varying])::text[])))
);


--
-- Name: fx_reconciliations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_reconciliations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_reconciliations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_reconciliations_id_seq OWNED BY public.fx_reconciliations.id;


--
-- Name: fx_stock_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_stock_movements (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    till_id bigint,
    currency character varying(3) NOT NULL,
    movement_type character varying(64) NOT NULL,
    amount_minor bigint NOT NULL,
    movement_date date NOT NULL,
    counterparty_name character varying(255),
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    journal_entry_id bigint,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    requested_by_user_id bigint,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    CONSTRAINT fx_stock_movements_amount_positive CHECK ((amount_minor > 0)),
    CONSTRAINT fx_stock_movements_status_valid CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'posted'::character varying, 'rejected'::character varying])::text[]))),
    CONSTRAINT fx_stock_movements_type_valid CHECK (((movement_type)::text = ANY ((ARRAY['partner_replenishment'::character varying, 'partner_sale'::character varying, 'adjustment_correction'::character varying])::text[])))
);


--
-- Name: fx_stock_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_stock_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_stock_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_stock_movements_id_seq OWNED BY public.fx_stock_movements.id;


--
-- Name: fx_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_transactions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    till_id bigint,
    client_id bigint,
    transaction_number character varying(64) NOT NULL,
    transaction_date date NOT NULL,
    direction character varying(32) NOT NULL,
    foreign_currency character varying(3) NOT NULL,
    foreign_amount_minor bigint NOT NULL,
    local_currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    local_amount_minor bigint NOT NULL,
    reference_rate numeric(20,8) NOT NULL,
    applied_rate numeric(20,8) NOT NULL,
    margin_rate numeric(12,6) DEFAULT '0'::numeric NOT NULL,
    margin_amount_minor bigint,
    client_name character varying(255),
    client_identity_number character varying(128),
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    journal_entry_id bigint,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    slip_number character varying(64),
    register_number character varying(64),
    client_identity_type character varying(64),
    client_identity_issuing_country character varying(2),
    CONSTRAINT fx_transactions_amounts_positive CHECK (((foreign_amount_minor > 0) AND (local_amount_minor > 0))),
    CONSTRAINT fx_transactions_direction_valid CHECK (((direction)::text = ANY ((ARRAY['buy_foreign_currency'::character varying, 'sell_foreign_currency'::character varying])::text[]))),
    CONSTRAINT fx_transactions_status_valid CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: fx_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_transactions_id_seq OWNED BY public.fx_transactions.id;


--
-- Name: hr_attendance_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_attendance_records (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    attendance_date date NOT NULL,
    checked_in_at timestamp(0) without time zone,
    checked_out_at timestamp(0) without time zone,
    late_minutes integer DEFAULT 0 NOT NULL,
    absence_minutes integer DEFAULT 0 NOT NULL,
    status character varying(32) DEFAULT 'present'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_attendance_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_attendance_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_attendance_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_attendance_records_id_seq OWNED BY public.hr_attendance_records.id;


--
-- Name: hr_contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_contracts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    contract_number character varying(64) NOT NULL,
    contract_type character varying(32) NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    base_salary_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    document_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    version integer DEFAULT 1 NOT NULL,
    predecessor_contract_id bigint
);


--
-- Name: hr_contracts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_contracts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_contracts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_contracts_id_seq OWNED BY public.hr_contracts.id;


--
-- Name: hr_employee_agency_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_employee_agency_history (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    agency_id bigint,
    starts_on date NOT NULL,
    ends_on date,
    reason character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_employee_agency_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_employee_agency_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_employee_agency_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_employee_agency_history_id_seq OWNED BY public.hr_employee_agency_history.id;


--
-- Name: hr_employee_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_employee_documents (
    id bigint NOT NULL,
    hr_employee_id bigint NOT NULL,
    document_id bigint NOT NULL,
    document_type character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_employee_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_employee_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_employee_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_employee_documents_id_seq OWNED BY public.hr_employee_documents.id;


--
-- Name: hr_employees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_employees (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    user_id bigint,
    agency_id bigint,
    supervisor_id bigint,
    employee_number character varying(64) NOT NULL,
    first_name character varying(128) NOT NULL,
    last_name character varying(128) NOT NULL,
    photo_path character varying(255),
    identity_number character varying(128),
    phone_number character varying(32),
    email character varying(255),
    job_title character varying(128),
    service_name character varying(128),
    hired_on date,
    contract_type character varying(32),
    base_salary_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    emergency_contact_name character varying(255),
    emergency_contact_phone character varying(32),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    professional_history text,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    gender character varying(32),
    birth_date date,
    birth_place character varying(128),
    portfolio_code character varying(64)
);


--
-- Name: hr_employees_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_employees_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_employees_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_employees_id_seq OWNED BY public.hr_employees.id;


--
-- Name: hr_leave_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_leave_requests (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    leave_type character varying(64) NOT NULL,
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    requested_by_user_id bigint,
    CONSTRAINT hr_leave_dates_valid CHECK ((ends_on >= starts_on)),
    CONSTRAINT hr_leave_requests_status_valid CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: hr_leave_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_leave_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_leave_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_leave_requests_id_seq OWNED BY public.hr_leave_requests.id;


--
-- Name: hr_payroll_formula_rates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_payroll_formula_rates (
    id bigint NOT NULL,
    hr_payroll_formula_set_id bigint NOT NULL,
    branch character varying(32) NOT NULL,
    sector character varying(32),
    payer character varying(16) NOT NULL,
    rate numeric(8,4) NOT NULL,
    ceiling_minor bigint,
    basis character varying(32) DEFAULT 'gross_salary'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT hr_payroll_formula_rates_payer_valid CHECK (((payer)::text = ANY ((ARRAY['employer'::character varying, 'employee'::character varying])::text[]))),
    CONSTRAINT hr_payroll_formula_rates_rate_non_negative CHECK (((rate >= (0)::numeric) AND ((ceiling_minor IS NULL) OR (ceiling_minor >= 0))))
);


--
-- Name: hr_payroll_formula_rates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_payroll_formula_rates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_payroll_formula_rates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_payroll_formula_rates_id_seq OWNED BY public.hr_payroll_formula_rates.id;


--
-- Name: hr_payroll_formula_sets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_payroll_formula_sets (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    jurisdiction character varying(32) DEFAULT 'cm'::character varying NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    effective_from date NOT NULL,
    effective_to date,
    status character varying(16) DEFAULT 'draft'::character varying NOT NULL,
    source_regulatory_source_id bigint,
    created_by_user_id bigint,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT hr_payroll_formula_sets_source_required CHECK ((source_regulatory_source_id IS NOT NULL)),
    CONSTRAINT hr_payroll_formula_sets_status_valid CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'superseded'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: hr_payroll_formula_sets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_payroll_formula_sets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_payroll_formula_sets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_payroll_formula_sets_id_seq OWNED BY public.hr_payroll_formula_sets.id;


--
-- Name: hr_payroll_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_payroll_lines (
    id bigint NOT NULL,
    hr_payroll_slip_id bigint NOT NULL,
    line_type character varying(64) NOT NULL,
    label character varying(255) NOT NULL,
    amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_payroll_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_payroll_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_payroll_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_payroll_lines_id_seq OWNED BY public.hr_payroll_lines.id;


--
-- Name: hr_payroll_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_payroll_runs (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint,
    period_key character varying(32) NOT NULL,
    period_starts_on date NOT NULL,
    period_ends_on date NOT NULL,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    gross_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    deduction_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    net_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    hr_payroll_formula_set_id bigint,
    formula_snapshot json,
    correction_of_run_id bigint,
    reversal_of_run_id bigint,
    created_by_user_id bigint,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone
);


--
-- Name: hr_payroll_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_payroll_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_payroll_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_payroll_runs_id_seq OWNED BY public.hr_payroll_runs.id;


--
-- Name: hr_payroll_slips; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_payroll_slips (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_payroll_run_id bigint NOT NULL,
    hr_employee_id bigint NOT NULL,
    slip_number character varying(64) NOT NULL,
    gross_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    deduction_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    net_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_payroll_slips_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_payroll_slips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_payroll_slips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_payroll_slips_id_seq OWNED BY public.hr_payroll_slips.id;


--
-- Name: hr_salary_advances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_salary_advances (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    amount_minor bigint NOT NULL,
    remaining_amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    granted_on date,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT hr_salary_advances_amounts_non_negative CHECK (((amount_minor > 0) AND (remaining_amount_minor >= 0)))
);


--
-- Name: hr_salary_advances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_salary_advances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_salary_advances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_salary_advances_id_seq OWNED BY public.hr_salary_advances.id;


--
-- Name: hr_sanctions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hr_sanctions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    hr_employee_id bigint NOT NULL,
    sanction_type character varying(64) NOT NULL,
    sanction_date date,
    deduction_amount_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    reason text,
    document_id bigint,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hr_sanctions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hr_sanctions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hr_sanctions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hr_sanctions_id_seq OWNED BY public.hr_sanctions.id;


--
-- Name: insurance_cancellations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_cancellations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_subscription_id bigint NOT NULL,
    effective_on date NOT NULL,
    reason text,
    refund_treatment character varying(32) DEFAULT 'none'::character varying NOT NULL,
    refund_amount_minor bigint,
    refund_customer_account_id bigint,
    refund_journal_entry_id bigint,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    requested_by_user_id bigint NOT NULL,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_cancellations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_cancellations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_cancellations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_cancellations_id_seq OWNED BY public.insurance_cancellations.id;


--
-- Name: insurance_claim_decisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_claim_decisions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_claim_id bigint NOT NULL,
    decision character varying(16) NOT NULL,
    indemnified_amount_minor bigint,
    settled_on date,
    notes text,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    requested_by_user_id bigint NOT NULL,
    requested_at timestamp(0) without time zone NOT NULL,
    reviewed_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    review_comments text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_claim_decisions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_claim_decisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_claim_decisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_claim_decisions_id_seq OWNED BY public.insurance_claim_decisions.id;


--
-- Name: insurance_claim_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_claim_documents (
    id bigint NOT NULL,
    insurance_claim_id bigint NOT NULL,
    document_id bigint NOT NULL,
    document_type character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_claim_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_claim_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_claim_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_claim_documents_id_seq OWNED BY public.insurance_claim_documents.id;


--
-- Name: insurance_claim_evidence_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_claim_evidence_configs (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_product_id bigint NOT NULL,
    claim_type character varying(64) NOT NULL,
    document_type character varying(64) NOT NULL,
    is_required boolean DEFAULT true NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_claim_evidence_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_claim_evidence_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_claim_evidence_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_claim_evidence_configs_id_seq OWNED BY public.insurance_claim_evidence_configs.id;


--
-- Name: insurance_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_claims (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    insurance_subscription_id bigint NOT NULL,
    claim_number character varying(64) NOT NULL,
    claim_type character varying(64) NOT NULL,
    incident_date date,
    description text,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    claimed_amount_minor bigint,
    indemnified_amount_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    settled_at timestamp(0) without time zone,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    evidence_complete_at timestamp(0) without time zone,
    reversal_at timestamp(0) without time zone,
    reversal_journal_entry_id bigint
);


--
-- Name: insurance_claims_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_claims_id_seq OWNED BY public.insurance_claims.id;


--
-- Name: insurance_endorsements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_endorsements (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_subscription_id bigint NOT NULL,
    endorsement_type character varying(64) NOT NULL,
    before_values json NOT NULL,
    after_values json NOT NULL,
    effective_on date NOT NULL,
    reason text,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    requested_by_user_id bigint NOT NULL,
    reviewed_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_endorsements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_endorsements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_endorsements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_endorsements_id_seq OWNED BY public.insurance_endorsements.id;


--
-- Name: insurance_export_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_export_records (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    export_type character varying(64) NOT NULL,
    agency_id bigint,
    generated_by_user_id bigint NOT NULL,
    filters json,
    checksum character varying(64),
    source_query_version character varying(64) NOT NULL,
    record_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_export_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_export_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_export_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_export_records_id_seq OWNED BY public.insurance_export_records.id;


--
-- Name: insurance_partners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_partners (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint,
    ledger_account_id bigint,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    phone_number character varying(32),
    email character varying(255),
    address text,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_partners_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_partners_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_partners_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_partners_id_seq OWNED BY public.insurance_partners.id;


--
-- Name: insurance_premium_assessments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_premium_assessments (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_subscription_id bigint NOT NULL,
    loan_id bigint,
    base_amount_minor bigint,
    rate numeric(12,6),
    premium_amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    due_on date,
    assessed_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'assessed'::character varying NOT NULL,
    journal_entry_id bigint,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    rule_version_id bigint,
    period_key character varying(128),
    CONSTRAINT insurance_premium_assessments_amount_positive CHECK ((premium_amount_minor > 0))
);


--
-- Name: insurance_premium_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_premium_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_premium_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_premium_assessments_id_seq OWNED BY public.insurance_premium_assessments.id;


--
-- Name: insurance_premium_payment_splits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_premium_payment_splits (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_premium_payment_id bigint NOT NULL,
    insurance_product_rule_version_split_id bigint,
    split_type character varying(64) NOT NULL,
    amount_minor bigint NOT NULL,
    ledger_account_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT insurance_premium_payment_splits_amount_positive CHECK ((amount_minor > 0))
);


--
-- Name: insurance_premium_payment_splits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_premium_payment_splits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_premium_payment_splits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_premium_payment_splits_id_seq OWNED BY public.insurance_premium_payment_splits.id;


--
-- Name: insurance_premium_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_premium_payments (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_premium_assessment_id bigint NOT NULL,
    customer_account_id bigint,
    teller_transaction_id bigint,
    journal_entry_id bigint,
    amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    payment_method character varying(64),
    paid_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    reversed_at timestamp(0) without time zone,
    reversal_journal_entry_id bigint,
    remitted_at timestamp(0) without time zone,
    remittance_batch_item_id bigint,
    CONSTRAINT insurance_premium_payments_amount_positive CHECK ((amount_minor > 0))
);


--
-- Name: insurance_premium_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_premium_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_premium_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_premium_payments_id_seq OWNED BY public.insurance_premium_payments.id;


--
-- Name: insurance_premium_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_premium_schedules (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_subscription_id bigint NOT NULL,
    rule_version_id bigint,
    period_number integer NOT NULL,
    due_on date NOT NULL,
    idempotency_key character varying(128) NOT NULL,
    insurance_premium_assessment_id bigint,
    status character varying(32) DEFAULT 'scheduled'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_premium_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_premium_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_premium_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_premium_schedules_id_seq OWNED BY public.insurance_premium_schedules.id;


--
-- Name: insurance_product_coverages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_product_coverages (
    id bigint NOT NULL,
    insurance_product_id bigint NOT NULL,
    coverage_code character varying(64) NOT NULL,
    coverage_name character varying(255) NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_product_coverages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_product_coverages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_product_coverages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_product_coverages_id_seq OWNED BY public.insurance_product_coverages.id;


--
-- Name: insurance_product_rule_version_splits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_product_rule_version_splits (
    id bigint NOT NULL,
    insurance_product_rule_version_id bigint NOT NULL,
    split_type character varying(64) NOT NULL,
    calculation_type character varying(32) DEFAULT 'percentage'::character varying NOT NULL,
    rate numeric(12,6),
    fixed_minor bigint,
    ledger_account_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_product_rule_version_splits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_product_rule_version_splits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_product_rule_version_splits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_product_rule_version_splits_id_seq OWNED BY public.insurance_product_rule_version_splits.id;


--
-- Name: insurance_product_rule_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_product_rule_versions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_product_id bigint NOT NULL,
    version_number integer DEFAULT 1 NOT NULL,
    calculation_type character varying(64) NOT NULL,
    base_description character varying(128),
    rate numeric(12,6),
    fixed_premium_minor bigint,
    cap_minor bigint,
    floor_minor bigint,
    frequency character varying(32) DEFAULT 'one_time'::character varying NOT NULL,
    source_reference character varying(512),
    effective_from date NOT NULL,
    effective_until date,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    created_by_user_id bigint NOT NULL,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT insurance_rule_versions_rate_or_fixed_set CHECK (((rate IS NOT NULL) OR (fixed_premium_minor IS NOT NULL)))
);


--
-- Name: insurance_product_rule_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_product_rule_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_product_rule_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_product_rule_versions_id_seq OWNED BY public.insurance_product_rule_versions.id;


--
-- Name: insurance_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_products (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_partner_id bigint,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    product_type character varying(64) NOT NULL,
    premium_calculation_type character varying(64),
    premium_rate numeric(12,6),
    fixed_premium_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    payment_mode character varying(64),
    is_refundable boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    approval_status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    business_model character varying(64),
    report_category character varying(64),
    new_business_enabled boolean DEFAULT true NOT NULL
);


--
-- Name: insurance_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_products_id_seq OWNED BY public.insurance_products.id;


--
-- Name: insurance_remittance_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_remittance_batches (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_partner_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    period_from date NOT NULL,
    period_to date NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    total_minor bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    created_by_user_id bigint NOT NULL,
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insurance_remittance_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_remittance_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_remittance_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_remittance_batches_id_seq OWNED BY public.insurance_remittance_batches.id;


--
-- Name: insurance_remittance_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_remittance_items (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    insurance_remittance_batch_id bigint NOT NULL,
    insurance_premium_payment_id bigint NOT NULL,
    insurance_product_id bigint NOT NULL,
    split_type character varying(64) NOT NULL,
    amount_minor bigint NOT NULL,
    ledger_account_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT insurance_remittance_items_amount_positive CHECK ((amount_minor > 0))
);


--
-- Name: insurance_remittance_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_remittance_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_remittance_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_remittance_items_id_seq OWNED BY public.insurance_remittance_items.id;


--
-- Name: insurance_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insurance_subscriptions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    loan_id bigint,
    insurance_product_id bigint NOT NULL,
    subscription_number character varying(64) NOT NULL,
    starts_on date,
    ends_on date,
    coverage_amount_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    lifecycle_status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    rule_version_id bigint,
    grace_period_ends_on date,
    cancelled_at timestamp(0) without time zone
);


--
-- Name: insurance_subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insurance_subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insurance_subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insurance_subscriptions_id_seq OWNED BY public.insurance_subscriptions.id;


--
-- Name: islamic_compliance_reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_compliance_reviews (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    islamic_financing_id bigint,
    reviewed_by_user_id bigint,
    decision character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    reviewed_at timestamp(0) without time zone,
    comments text,
    checklist json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    islamic_product_id bigint,
    requested_by_user_id bigint,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    CONSTRAINT islamic_compliance_reviews_status_valid CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[]))),
    CONSTRAINT islamic_compliance_reviews_target_check CHECK ((((islamic_product_id IS NOT NULL) AND (islamic_financing_id IS NULL)) OR ((islamic_financing_id IS NOT NULL) AND (islamic_product_id IS NULL))))
);


--
-- Name: islamic_compliance_reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_compliance_reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_compliance_reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_compliance_reviews_id_seq OWNED BY public.islamic_compliance_reviews.id;


--
-- Name: islamic_financed_assets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_financed_assets (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    islamic_financing_id bigint NOT NULL,
    asset_type character varying(64) NOT NULL,
    description character varying(255) NOT NULL,
    purchase_amount_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    ownership_status character varying(64),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: islamic_financed_assets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_financed_assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_financed_assets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_financed_assets_id_seq OWNED BY public.islamic_financed_assets.id;


--
-- Name: islamic_financing_installments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_financing_installments (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    islamic_financing_id bigint NOT NULL,
    installment_number smallint NOT NULL,
    due_on date NOT NULL,
    amount_minor bigint NOT NULL,
    paid_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT islamic_financing_installments_amount_positive CHECK (((amount_minor > 0) AND (paid_amount_minor >= 0))),
    CONSTRAINT islamic_financing_installments_status_valid CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'overdue'::character varying])::text[])))
);


--
-- Name: islamic_financing_installments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_financing_installments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_financing_installments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_financing_installments_id_seq OWNED BY public.islamic_financing_installments.id;


--
-- Name: islamic_financings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_financings (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    islamic_product_id bigint NOT NULL,
    loan_id bigint,
    contract_number character varying(64) NOT NULL,
    contract_type character varying(64) NOT NULL,
    financed_amount_minor bigint NOT NULL,
    sale_price_minor bigint,
    residual_value_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    starts_on date,
    ends_on date,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    terms json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    purchase_cost_minor bigint,
    allowed_costs_minor bigint DEFAULT '0'::bigint NOT NULL,
    markup_minor bigint DEFAULT '0'::bigint NOT NULL,
    supplier_name character varying(255),
    approved_by_user_id bigint,
    approved_at timestamp(0) without time zone,
    journal_entry_id bigint,
    CONSTRAINT islamic_financings_amounts_non_negative CHECK (((financed_amount_minor > 0) AND ((purchase_cost_minor IS NULL) OR (purchase_cost_minor > 0)) AND (allowed_costs_minor >= 0) AND (markup_minor >= 0))),
    CONSTRAINT islamic_financings_murabaha_pricing_valid CHECK ((((contract_type)::text <> 'murabaha'::text) OR ((sale_price_minor IS NOT NULL) AND (sale_price_minor = ((COALESCE(purchase_cost_minor, financed_amount_minor) + COALESCE(allowed_costs_minor, (0)::bigint)) + COALESCE(markup_minor, (0)::bigint))))))
);


--
-- Name: islamic_financings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_financings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_financings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_financings_id_seq OWNED BY public.islamic_financings.id;


--
-- Name: islamic_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_products (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    contract_type character varying(64) NOT NULL,
    default_margin_rate numeric(12,6),
    profit_sharing_method character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: islamic_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_products_id_seq OWNED BY public.islamic_products.id;


--
-- Name: islamic_profit_sharing_terms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.islamic_profit_sharing_terms (
    id bigint NOT NULL,
    islamic_financing_id bigint NOT NULL,
    institution_share_rate numeric(12,6) NOT NULL,
    client_share_rate numeric(12,6) NOT NULL,
    loss_sharing_rule character varying(128),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT islamic_profit_sharing_terms_rates_valid CHECK (((institution_share_rate >= (0)::numeric) AND (client_share_rate >= (0)::numeric)))
);


--
-- Name: islamic_profit_sharing_terms_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.islamic_profit_sharing_terms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: islamic_profit_sharing_terms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.islamic_profit_sharing_terms_id_seq OWNED BY public.islamic_profit_sharing_terms.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: journal_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journal_entries (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    reference character varying(64) NOT NULL,
    business_date date NOT NULL,
    posted_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    source_module character varying(64),
    source_type character varying(64),
    source_public_id character varying(64),
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    description text,
    created_by_user_id bigint,
    posted_by_user_id bigint,
    reversed_by_user_id bigint,
    reversal_of_journal_entry_id bigint,
    idempotency_key character varying(128),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    submitted_at timestamp(0) without time zone,
    submitted_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    reviewed_by_user_id bigint,
    review_comment text,
    rejection_reason text,
    CONSTRAINT journal_entries_operational_agency_required CHECK (((agency_id IS NOT NULL) OR ((source_module)::text = 'institution'::text))),
    CONSTRAINT journal_entries_post_metadata_consistent CHECK (((((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])) AND (posted_at IS NOT NULL) AND (posted_by_user_id IS NOT NULL)) OR ((status)::text <> ALL ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))),
    CONSTRAINT journal_entries_rejection_reason_consistent CHECK (((((status)::text = 'rejected'::text) AND (rejection_reason IS NOT NULL)) OR ((status)::text <> 'rejected'::text))),
    CONSTRAINT journal_entries_reversal_metadata_consistent CHECK (((((status)::text = 'reversed'::text) AND (reversed_by_user_id IS NOT NULL)) OR ((status)::text <> 'reversed'::text))),
    CONSTRAINT journal_entries_review_metadata_consistent CHECK (((((status)::text = ANY ((ARRAY['approved'::character varying, 'rejected'::character varying])::text[])) AND (reviewed_at IS NOT NULL) AND (reviewed_by_user_id IS NOT NULL)) OR ((status)::text <> ALL ((ARRAY['approved'::character varying, 'rejected'::character varying])::text[])))),
    CONSTRAINT journal_entries_status_allowed CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'submitted'::character varying, 'approved'::character varying, 'rejected'::character varying, 'posted'::character varying, 'reversed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: journal_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.journal_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: journal_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.journal_entries_id_seq OWNED BY public.journal_entries.id;


--
-- Name: journal_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journal_lines (
    id bigint NOT NULL,
    journal_entry_id bigint NOT NULL,
    ledger_account_id bigint NOT NULL,
    customer_account_id bigint,
    loan_id bigint,
    debit_minor bigint DEFAULT '0'::bigint NOT NULL,
    credit_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) NOT NULL,
    line_memo character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    public_id character(26) NOT NULL,
    CONSTRAINT journal_lines_credit_non_negative CHECK ((credit_minor >= 0)),
    CONSTRAINT journal_lines_debit_non_negative CHECK ((debit_minor >= 0)),
    CONSTRAINT journal_lines_exactly_one_side_positive CHECK ((((debit_minor > 0) AND (credit_minor = 0)) OR ((credit_minor > 0) AND (debit_minor = 0))))
);


--
-- Name: journal_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.journal_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: journal_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.journal_lines_id_seq OWNED BY public.journal_lines.id;


--
-- Name: ledger_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ledger_accounts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    account_class character varying(32) NOT NULL,
    account_type character varying(64),
    parent_account_id bigint,
    normal_balance_side character varying(6) NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT ledger_accounts_not_self_parent CHECK (((parent_account_id IS NULL) OR (parent_account_id <> id)))
);


--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ledger_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ledger_accounts_id_seq OWNED BY public.ledger_accounts.id;


--
-- Name: loan_approvals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_approvals (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    step character varying(32) NOT NULL,
    decision character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    acted_by_user_id bigint,
    acted_at timestamp(0) without time zone,
    comments text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: loan_approvals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_approvals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_approvals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_approvals_id_seq OWNED BY public.loan_approvals.id;


--
-- Name: loan_arrears; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_arrears (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    loan_schedule_line_id bigint,
    due_on date NOT NULL,
    original_due_minor bigint NOT NULL,
    paid_minor bigint DEFAULT '0'::bigint NOT NULL,
    unpaid_minor bigint NOT NULL,
    penalty_base_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'open'::character varying NOT NULL,
    last_penalized_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_arrears_amounts_non_negative CHECK (((original_due_minor >= 0) AND (paid_minor >= 0) AND (unpaid_minor >= 0) AND ((penalty_base_minor IS NULL) OR (penalty_base_minor >= 0))))
);


--
-- Name: loan_arrears_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_arrears_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_arrears_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_arrears_id_seq OWNED BY public.loan_arrears.id;


--
-- Name: loan_charge_assessments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_charge_assessments (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    loan_schedule_line_id bigint,
    charge_type character varying(64) NOT NULL,
    base_amount_minor bigint,
    rate numeric(12,6),
    assessed_amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    assessed_at timestamp(0) without time zone,
    due_on date,
    status character varying(32) DEFAULT 'assessed'::character varying NOT NULL,
    paid_at timestamp(0) without time zone,
    journal_entry_id bigint,
    reversal_journal_entry_id bigint,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_charge_assessments_amount_non_negative CHECK (((assessed_amount_minor >= 0) AND ((base_amount_minor IS NULL) OR (base_amount_minor >= 0))))
);


--
-- Name: loan_charge_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_charge_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_charge_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_charge_assessments_id_seq OWNED BY public.loan_charge_assessments.id;


--
-- Name: loan_disbursements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_disbursements (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    loan_id bigint NOT NULL,
    journal_entry_id bigint NOT NULL,
    transfer_account_id bigint,
    disbursement_channel character varying(32) NOT NULL,
    principal_amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    posted_at timestamp(0) without time zone,
    posted_by_user_id bigint,
    idempotency_key character varying(128),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_disbursements_channel_allowed CHECK (((disbursement_channel)::text = ANY ((ARRAY['transfer_account'::character varying, 'cash'::character varying])::text[]))),
    CONSTRAINT loan_disbursements_principal_positive CHECK ((principal_amount_minor > 0)),
    CONSTRAINT loan_disbursements_status_allowed CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: loan_disbursements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_disbursements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_disbursements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_disbursements_id_seq OWNED BY public.loan_disbursements.id;


--
-- Name: loan_guarantee_obligations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_guarantee_obligations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    loan_id bigint NOT NULL,
    client_guarantor_id bigint NOT NULL,
    obligation_type character varying(64) DEFAULT 'personal_guarantee'::character varying NOT NULL,
    obligation_amount_minor bigint,
    obligation_percentage numeric(9,6),
    currency character varying(3),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    starts_on date,
    ends_on date,
    release_condition character varying(128),
    released_at timestamp(0) without time zone,
    released_by_user_id bigint,
    document_id bigint,
    guarantor_identity_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_guarantee_obligations_amount_non_negative CHECK (((obligation_amount_minor IS NULL) OR (obligation_amount_minor >= 0))),
    CONSTRAINT loan_guarantee_obligations_percentage_valid CHECK (((obligation_percentage IS NULL) OR ((obligation_percentage >= (0)::numeric) AND (obligation_percentage <= (100)::numeric)))),
    CONSTRAINT loan_guarantee_obligations_release_consistent CHECK (((((status)::text <> 'released'::text) AND (released_at IS NULL)) OR (((status)::text = 'released'::text) AND (released_at IS NOT NULL))))
);


--
-- Name: loan_guarantee_obligations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_guarantee_obligations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_guarantee_obligations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_guarantee_obligations_id_seq OWNED BY public.loan_guarantee_obligations.id;


--
-- Name: loan_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_products (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    min_term_count smallint,
    max_term_count smallint,
    term_unit character varying(16),
    allowed_repayment_frequencies json,
    requires_guarantor boolean DEFAULT false NOT NULL,
    requires_collateral boolean DEFAULT false NOT NULL,
    interest_policy_key character varying(128),
    penalty_policy_key character varying(128),
    repayment_allocation_policy_key character varying(128),
    fee_policy_key character varying(128),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    ledger_account_id bigint,
    min_amount_minor bigint,
    max_amount_minor bigint,
    due_date_day smallint,
    penalty_grace_days smallint,
    min_grace_period_days smallint,
    max_grace_period_days smallint,
    interest_rate numeric(12,6),
    tax_rate numeric(12,6),
    insurance_rate numeric(12,6),
    fee_amount_minor bigint,
    floor_amount_minor bigint,
    tax_policy_key character varying(128),
    insurance_policy_key character varying(128),
    guarantee_deposit_policy_key character varying(128),
    guarantee_deposit_type character varying(32),
    guarantee_deposit_value numeric(18,6),
    penalty_formula_type character varying(64),
    penalty_formula_base character varying(64),
    penalty_value_type character varying(32),
    penalty_value numeric(18,6),
    operation_type character varying(64),
    constant_value numeric(18,6),
    rules json,
    CONSTRAINT loan_products_amount_limits_valid CHECK (((min_amount_minor IS NULL) OR (max_amount_minor IS NULL) OR (max_amount_minor >= min_amount_minor))),
    CONSTRAINT loan_products_due_date_valid CHECK (((due_date_day IS NULL) OR ((due_date_day >= 1) AND (due_date_day <= 31))))
);


--
-- Name: loan_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_products_id_seq OWNED BY public.loan_products.id;


--
-- Name: loan_recovery_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_recovery_accounts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    customer_account_id bigint NOT NULL,
    priority smallint DEFAULT '1'::smallint NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    mandate_metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: loan_recovery_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_recovery_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_recovery_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_recovery_accounts_id_seq OWNED BY public.loan_recovery_accounts.id;


--
-- Name: loan_recovery_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_recovery_attempts (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    loan_recovery_account_id bigint,
    customer_account_id bigint,
    batch_run_id bigint,
    requested_amount_minor bigint NOT NULL,
    recovered_amount_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    attempted_at timestamp(0) without time zone,
    failure_reason text,
    teller_transaction_id bigint,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_recovery_attempts_amounts_non_negative CHECK (((requested_amount_minor >= 0) AND (recovered_amount_minor >= 0)))
);


--
-- Name: loan_recovery_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_recovery_attempts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_recovery_attempts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_recovery_attempts_id_seq OWNED BY public.loan_recovery_attempts.id;


--
-- Name: loan_repayment_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_repayment_allocations (
    id bigint NOT NULL,
    loan_repayment_id bigint NOT NULL,
    loan_schedule_line_id bigint NOT NULL,
    component character varying(32) NOT NULL,
    amount_minor bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_repayment_allocations_amount_positive CHECK ((amount_minor > 0)),
    CONSTRAINT loan_repayment_allocations_component_allowed CHECK (((component)::text = ANY ((ARRAY['principal'::character varying, 'interest'::character varying, 'fees'::character varying, 'insurance'::character varying, 'tax'::character varying, 'penalty'::character varying])::text[])))
);


--
-- Name: loan_repayment_allocations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_repayment_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_repayment_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_repayment_allocations_id_seq OWNED BY public.loan_repayment_allocations.id;


--
-- Name: loan_repayments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_repayments (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    loan_id bigint NOT NULL,
    journal_entry_id bigint NOT NULL,
    customer_account_id bigint NOT NULL,
    received_amount_minor bigint NOT NULL,
    allocated_amount_minor bigint NOT NULL,
    overpayment_retained_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL,
    paid_on date NOT NULL,
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    posted_at timestamp(0) without time zone,
    posted_by_user_id bigint,
    idempotency_key character varying(128),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT loan_repayments_amounts_non_negative CHECK (((received_amount_minor > 0) AND (allocated_amount_minor >= 0) AND (overpayment_retained_minor >= 0) AND (received_amount_minor >= allocated_amount_minor))),
    CONSTRAINT loan_repayments_status_allowed CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: loan_repayments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_repayments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_repayments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_repayments_id_seq OWNED BY public.loan_repayments.id;


--
-- Name: loan_schedule_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_schedule_lines (
    id bigint NOT NULL,
    loan_schedule_snapshot_id bigint NOT NULL,
    installment_number smallint NOT NULL,
    due_date date NOT NULL,
    principal_minor bigint DEFAULT '0'::bigint NOT NULL,
    interest_minor bigint DEFAULT '0'::bigint NOT NULL,
    fees_minor bigint DEFAULT '0'::bigint NOT NULL,
    insurance_minor bigint DEFAULT '0'::bigint NOT NULL,
    tax_minor bigint DEFAULT '0'::bigint NOT NULL,
    currency character varying(3) NOT NULL,
    status character varying(32) DEFAULT 'scheduled'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    penalty_minor bigint DEFAULT '0'::bigint NOT NULL,
    capitalized_interest_minor bigint DEFAULT '0'::bigint NOT NULL,
    remaining_principal_minor bigint,
    total_installment_minor bigint,
    CONSTRAINT loan_schedule_lines_added_amounts_non_negative CHECK (((penalty_minor >= 0) AND (capitalized_interest_minor >= 0) AND ((remaining_principal_minor IS NULL) OR (remaining_principal_minor >= 0)) AND ((total_installment_minor IS NULL) OR (total_installment_minor >= 0)))),
    CONSTRAINT loan_schedule_lines_amounts_non_negative CHECK (((principal_minor >= 0) AND (interest_minor >= 0) AND (fees_minor >= 0) AND (insurance_minor >= 0) AND (tax_minor >= 0)))
);


--
-- Name: loan_schedule_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_schedule_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_schedule_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_schedule_lines_id_seq OWNED BY public.loan_schedule_lines.id;


--
-- Name: loan_schedule_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_schedule_snapshots (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    formula_engine_key character varying(128) NOT NULL,
    formula_engine_version character varying(64),
    policy_snapshot_hash character varying(128) NOT NULL,
    generated_by_user_id bigint,
    generated_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: loan_schedule_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_schedule_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_schedule_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_schedule_snapshots_id_seq OWNED BY public.loan_schedule_snapshots.id;


--
-- Name: loan_status_transitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_status_transitions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    loan_id bigint NOT NULL,
    from_status character varying(32),
    to_status character varying(32) NOT NULL,
    actor_user_id bigint,
    decision character varying(32),
    reason text,
    notes text,
    document_id bigint,
    transitioned_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    checker_decision character varying(32),
    checked_by_user_id bigint,
    checked_at timestamp(0) without time zone,
    agency_id bigint NOT NULL
);


--
-- Name: loan_status_transitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_status_transitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_status_transitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_status_transitions_id_seq OWNED BY public.loan_status_transitions.id;


--
-- Name: loan_transfers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loan_transfers (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    loan_id bigint,
    initial_manager_id bigint NOT NULL,
    new_manager_id bigint NOT NULL,
    transfer_reason character varying(255) NOT NULL,
    transfer_date date NOT NULL,
    approved_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: loan_transfers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loan_transfers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loan_transfers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loan_transfers_id_seq OWNED BY public.loan_transfers.id;


--
-- Name: loans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loans (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    client_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    loan_product_id bigint NOT NULL,
    loan_number character varying(64) NOT NULL,
    requested_amount_minor bigint NOT NULL,
    approved_principal_minor bigint,
    currency character varying(3) NOT NULL,
    applied_on date NOT NULL,
    approved_on date,
    disbursed_on date,
    closed_on date,
    status character varying(32) DEFAULT 'application'::character varying NOT NULL,
    purpose text,
    sector_id bigint,
    sub_sector_id bigint,
    formula_policy_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    credit_agent_id bigint,
    amortization_account_id bigint,
    unpaid_account_id bigint,
    recovery_account_id bigint,
    transfer_account_id bigint,
    processing_level character varying(64),
    financed_activity_code character varying(64),
    activity_address text,
    entrepreneur_address text,
    applied_interest_rate numeric(12,6),
    applied_tax_rate numeric(12,6),
    first_installment_date date,
    number_of_installments smallint,
    grace_period_duration smallint,
    tranche_duration smallint,
    total_loan_duration smallint,
    dossier_fees_minor bigint,
    dossier_fees_tax_minor bigint,
    guarantee_deposit_amount_minor bigint,
    insurance_amount_minor bigint,
    outstanding_principal_minor bigint,
    installment_amount_minor bigint,
    total_unpaid_amount_minor bigint,
    due_amount_minor bigint,
    total_interest_repaid_minor bigint DEFAULT '0'::bigint NOT NULL,
    total_penalties_paid_minor bigint DEFAULT '0'::bigint NOT NULL,
    total_principal_repaid_minor bigint DEFAULT '0'::bigint NOT NULL,
    installments_repaid_count smallint DEFAULT '0'::smallint NOT NULL,
    last_repayment_date date,
    next_repayment_date date,
    global_outstanding_amount_minor bigint,
    capitalized_interest_minor bigint DEFAULT '0'::bigint NOT NULL,
    cumulative_capitalized_interest_minor bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT loans_approved_principal_positive CHECK (((approved_principal_minor IS NULL) OR (approved_principal_minor > 0))),
    CONSTRAINT loans_projection_amounts_non_negative CHECK ((((dossier_fees_minor IS NULL) OR (dossier_fees_minor >= 0)) AND ((dossier_fees_tax_minor IS NULL) OR (dossier_fees_tax_minor >= 0)) AND ((guarantee_deposit_amount_minor IS NULL) OR (guarantee_deposit_amount_minor >= 0)) AND ((insurance_amount_minor IS NULL) OR (insurance_amount_minor >= 0)) AND ((outstanding_principal_minor IS NULL) OR (outstanding_principal_minor >= 0)) AND ((installment_amount_minor IS NULL) OR (installment_amount_minor >= 0)) AND ((total_unpaid_amount_minor IS NULL) OR (total_unpaid_amount_minor >= 0)) AND ((due_amount_minor IS NULL) OR (due_amount_minor >= 0)))),
    CONSTRAINT loans_requested_amount_positive CHECK ((requested_amount_minor > 0)),
    CONSTRAINT loans_sub_sector_requires_sector CHECK (((sub_sector_id IS NULL) OR (sector_id IS NOT NULL)))
);


--
-- Name: loans_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.loans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: loans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.loans_id_seq OWNED BY public.loans.id;


--
-- Name: media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media (
    id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL,
    uuid uuid,
    collection_name character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    file_name character varying(255) NOT NULL,
    mime_type character varying(255),
    disk character varying(255) NOT NULL,
    conversions_disk character varying(255),
    size bigint NOT NULL,
    manipulations json NOT NULL,
    custom_properties json NOT NULL,
    generated_conversions json NOT NULL,
    responsive_images json NOT NULL,
    order_column integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: media_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.media_id_seq OWNED BY public.media.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


--
-- Name: notification_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_deliveries (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    notification_template_id bigint,
    recipient_type character varying(255),
    recipient_id bigint,
    channel character varying(32) NOT NULL,
    destination character varying(255) NOT NULL,
    subject character varying(255),
    body text NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    scheduled_at timestamp(0) without time zone,
    sent_at timestamp(0) without time zone,
    failure_reason text,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    retry_count smallint DEFAULT '0'::smallint NOT NULL,
    max_attempts smallint DEFAULT '3'::smallint NOT NULL,
    last_attempt_at timestamp(0) without time zone,
    next_attempt_at timestamp(0) without time zone,
    category character varying(64),
    idempotency_key character varying(191),
    CONSTRAINT notification_deliveries_status_valid CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'sent'::character varying, 'failed'::character varying, 'cancelled'::character varying, 'permanently_failed'::character varying])::text[])))
);


--
-- Name: notification_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_deliveries_id_seq OWNED BY public.notification_deliveries.id;


--
-- Name: notification_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_templates (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    channel character varying(32) NOT NULL,
    subject character varying(255),
    body_template text NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    version integer DEFAULT 1 NOT NULL,
    category character varying(64),
    language character varying(8) DEFAULT 'fr'::character varying NOT NULL,
    variables_allowlist json,
    effective_from timestamp(0) without time zone,
    effective_to timestamp(0) without time zone
);


--
-- Name: notification_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_templates_id_seq OWNED BY public.notification_templates.id;


--
-- Name: operation_account_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operation_account_mappings (
    id bigint NOT NULL,
    operation_code_id bigint NOT NULL,
    debit_ledger_account_id bigint,
    credit_ledger_account_id bigint,
    currency character varying(3),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    public_id character(26) NOT NULL
);


--
-- Name: operation_account_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.operation_account_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: operation_account_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.operation_account_mappings_id_seq OWNED BY public.operation_account_mappings.id;


--
-- Name: operation_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operation_codes (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    label character varying(255) NOT NULL,
    module character varying(64) NOT NULL,
    operation_type character varying(64),
    direction character varying(32),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: operation_codes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.operation_codes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: operation_codes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.operation_codes_id_seq OWNED BY public.operation_codes.id;


--
-- Name: otp_challenges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.otp_challenges (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    purpose character varying(64) NOT NULL,
    phone_number character varying(255) NOT NULL,
    code_hash character varying(255) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    used_at timestamp(0) without time zone,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    max_attempts smallint DEFAULT '5'::smallint NOT NULL,
    last_sent_at timestamp(0) without time zone,
    resend_count smallint DEFAULT '0'::smallint NOT NULL,
    created_ip character varying(45),
    created_user_agent text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: otp_challenges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.otp_challenges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: otp_challenges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.otp_challenges_id_seq OWNED BY public.otp_challenges.id;


--
-- Name: otp_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.otp_deliveries (
    id bigint NOT NULL,
    otp_challenge_id bigint NOT NULL,
    channel character varying(32) NOT NULL,
    destination_hash character varying(64) NOT NULL,
    destination_masked character varying(255) NOT NULL,
    status character varying(32) NOT NULL,
    provider_reference character varying(255),
    error_summary text,
    sent_at timestamp(0) without time zone,
    failed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    retry_count smallint DEFAULT '0'::smallint NOT NULL,
    max_attempts smallint DEFAULT '3'::smallint NOT NULL,
    last_attempt_at timestamp(0) without time zone,
    next_attempt_at timestamp(0) without time zone
);


--
-- Name: otp_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.otp_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: otp_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.otp_deliveries_id_seq OWNED BY public.otp_deliveries.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: reference_sequences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reference_sequences (
    id bigint NOT NULL,
    key character varying(64) NOT NULL,
    prefix character varying(32) NOT NULL,
    padding smallint DEFAULT '6'::smallint NOT NULL,
    next_number bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: reference_sequences_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reference_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reference_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reference_sequences_id_seq OWNED BY public.reference_sequences.id;


--
-- Name: regulatory_sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regulatory_sources (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    authority character varying(32) NOT NULL,
    reference character varying(191) NOT NULL,
    title character varying(255) NOT NULL,
    effective_date date,
    checksum character varying(128) NOT NULL,
    imported_by_user_id bigint,
    imported_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT regulatory_sources_authority_valid CHECK (((authority)::text = ANY ((ARRAY['cobac'::character varying, 'beac'::character varying, 'cima'::character varying, 'ohada'::character varying, 'cnps'::character varying, 'aaoifi'::character varying, 'other'::character varying])::text[])))
);


--
-- Name: regulatory_sources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regulatory_sources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regulatory_sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regulatory_sources_id_seq OWNED BY public.regulatory_sources.id;


--
-- Name: report_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_definitions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(64) NOT NULL,
    name character varying(255) NOT NULL,
    report_type character varying(64) NOT NULL,
    module character varying(64),
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    definition json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    regulatory_source_id bigint,
    version integer DEFAULT 1 NOT NULL,
    effective_from date,
    effective_to date
);


--
-- Name: report_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.report_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.report_definitions_id_seq OWNED BY public.report_definitions.id;


--
-- Name: report_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_runs (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    report_definition_id bigint NOT NULL,
    agency_id bigint,
    period_starts_on date,
    period_ends_on date,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    generated_at timestamp(0) without time zone,
    generated_by_user_id bigint,
    document_id bigint,
    parameters json,
    summary json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    review_status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    reviewed_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    review_comments text,
    submitted_at timestamp(0) without time zone,
    submitted_by_user_id bigint,
    submission_channel character varying(32),
    submission_reference character varying(191),
    source_version_snapshot json,
    CONSTRAINT report_runs_review_status_valid CHECK (((review_status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: report_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.report_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.report_runs_id_seq OWNED BY public.report_runs.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sectors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sectors (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    code character varying(32) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sectors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sectors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sectors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sectors_id_seq OWNED BY public.sectors.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: sms_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sms_messages (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    owner_type character varying(255),
    owner_id bigint,
    phone_number character varying(32) NOT NULL,
    message text NOT NULL,
    direction character varying(32) DEFAULT 'outbound'::character varying NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    provider_reference character varying(255),
    sent_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sms_messages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sms_messages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sms_messages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sms_messages_id_seq OWNED BY public.sms_messages.id;


--
-- Name: staff_agency_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.staff_agency_assignments (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    role_at_agency character varying(64) DEFAULT 'staff'::character varying NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    is_primary boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    public_id character(26) NOT NULL,
    CONSTRAINT staff_assignment_dates_valid CHECK (((ends_on IS NULL) OR (ends_on >= starts_on)))
);


--
-- Name: staff_agency_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.staff_agency_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: staff_agency_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.staff_agency_assignments_id_seq OWNED BY public.staff_agency_assignments.id;


--
-- Name: sub_sectors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sub_sectors (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    sector_id bigint NOT NULL,
    code character varying(32) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sub_sectors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sub_sectors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sub_sectors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sub_sectors_id_seq OWNED BY public.sub_sectors.id;


--
-- Name: teller_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teller_sessions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    till_id bigint NOT NULL,
    agency_id bigint NOT NULL,
    teller_user_id bigint NOT NULL,
    business_date date NOT NULL,
    opened_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    opening_declaration_minor bigint,
    closing_declaration_minor bigint,
    currency character varying(3),
    status character varying(32) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT teller_sessions_closing_declaration_non_negative CHECK (((closing_declaration_minor IS NULL) OR (closing_declaration_minor >= 0))),
    CONSTRAINT teller_sessions_opening_declaration_non_negative CHECK (((opening_declaration_minor IS NULL) OR (opening_declaration_minor >= 0)))
);


--
-- Name: teller_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.teller_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: teller_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.teller_sessions_id_seq OWNED BY public.teller_sessions.id;


--
-- Name: teller_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teller_transactions (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    teller_session_id bigint NOT NULL,
    transaction_type character varying(64) NOT NULL,
    client_id bigint,
    customer_account_id bigint,
    loan_id bigint,
    amount_minor bigint NOT NULL,
    currency character varying(3) NOT NULL,
    status character varying(32) DEFAULT 'posted'::character varying NOT NULL,
    reference character varying(64) NOT NULL,
    idempotency_key character varying(128),
    journal_entry_id bigint,
    reversal_of_teller_transaction_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    review_status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    reviewed_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    agency_id bigint NOT NULL,
    transaction_date date,
    till_id bigint,
    event_number character varying(64),
    offset_ledger_account_id bigint,
    operation_code_id bigint,
    operation_code character varying(64),
    depositor_name character varying(255),
    depositor_address character varying(255),
    description text,
    initiator_type character varying(32) DEFAULT 'staff_on_behalf'::character varying NOT NULL,
    initiator_proxy_id bigint,
    customer_account_signature_id bigint,
    signature_checked_at timestamp(0) without time zone,
    signature_checked_by_user_id bigint,
    signature_verification_method character varying(32),
    CONSTRAINT teller_transactions_amount_positive CHECK ((amount_minor > 0)),
    CONSTRAINT teller_transactions_initiator_type_check CHECK (((initiator_type)::text = ANY ((ARRAY['holder'::character varying, 'proxy'::character varying, 'staff_on_behalf'::character varying, 'system'::character varying])::text[]))),
    CONSTRAINT teller_transactions_proxy_initiator_link_check CHECK ((((initiator_type)::text = 'proxy'::text) = (initiator_proxy_id IS NOT NULL))),
    CONSTRAINT teller_transactions_signature_check_fields_check CHECK ((((customer_account_signature_id IS NULL) AND (signature_checked_at IS NULL) AND (signature_checked_by_user_id IS NULL) AND (signature_verification_method IS NULL)) OR ((customer_account_signature_id IS NOT NULL) AND (signature_checked_at IS NOT NULL) AND (signature_checked_by_user_id IS NOT NULL) AND (signature_verification_method IS NOT NULL)))),
    CONSTRAINT teller_transactions_signature_method_check CHECK (((signature_verification_method IS NULL) OR ((signature_verification_method)::text = ANY ((ARRAY['visual_match'::character varying, 'thumbprint_match'::character varying, 'verified_proxy_mandate'::character varying, 'exception_override'::character varying])::text[]))))
);


--
-- Name: teller_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.teller_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: teller_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.teller_transactions_id_seq OWNED BY public.teller_transactions.id;


--
-- Name: till_currency_balances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.till_currency_balances (
    id bigint NOT NULL,
    till_id bigint NOT NULL,
    currency character varying(3) NOT NULL,
    opening_balance_minor bigint DEFAULT '0'::bigint NOT NULL,
    current_balance_minor bigint DEFAULT '0'::bigint NOT NULL,
    last_closing_balance_minor bigint,
    last_reconciled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT till_currency_balances_non_negative CHECK (((opening_balance_minor >= 0) AND (current_balance_minor >= 0) AND ((last_closing_balance_minor IS NULL) OR (last_closing_balance_minor >= 0))))
);


--
-- Name: till_currency_balances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.till_currency_balances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: till_currency_balances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.till_currency_balances_id_seq OWNED BY public.till_currency_balances.id;


--
-- Name: till_reconciliation_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.till_reconciliation_lines (
    id bigint NOT NULL,
    till_reconciliation_id bigint NOT NULL,
    denomination_id bigint NOT NULL,
    count integer DEFAULT 0 NOT NULL,
    declared_amount_minor bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT till_reconciliation_lines_declared_amount_non_negative CHECK (((declared_amount_minor IS NULL) OR (declared_amount_minor >= 0)))
);


--
-- Name: till_reconciliation_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.till_reconciliation_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: till_reconciliation_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.till_reconciliation_lines_id_seq OWNED BY public.till_reconciliation_lines.id;


--
-- Name: till_reconciliations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.till_reconciliations (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    teller_session_id bigint NOT NULL,
    counted_by_user_id bigint,
    counted_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'draft'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    review_status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    reviewed_by_user_id bigint,
    reviewed_at timestamp(0) without time zone,
    revision integer DEFAULT 1 NOT NULL,
    superseded_by_till_reconciliation_id bigint,
    reconciliation_date timestamp(0) without time zone,
    theoretical_balance_minor bigint,
    actual_balance_minor bigint,
    difference_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL
);


--
-- Name: till_reconciliations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.till_reconciliations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: till_reconciliations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.till_reconciliations_id_seq OWNED BY public.till_reconciliations.id;


--
-- Name: tills; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tills (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    agency_id bigint NOT NULL,
    code character varying(32) NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(32) DEFAULT 'counter'::character varying NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    assigned_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    ledger_account_id bigint,
    daily_state character varying(32) DEFAULT 'closed'::character varying NOT NULL,
    opening_balance_minor bigint,
    last_closing_balance_minor bigint,
    last_closing_at timestamp(0) without time zone,
    requires_denominations boolean DEFAULT true NOT NULL,
    nature character varying(64),
    is_central_till boolean DEFAULT false NOT NULL,
    max_balance_limit_minor bigint,
    max_withdrawal_limit_minor bigint,
    currency character varying(3) DEFAULT 'XAF'::character varying NOT NULL
);


--
-- Name: tills_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tills_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tills_id_seq OWNED BY public.tills.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    public_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    phone_number character varying(255) NOT NULL,
    phone_verified_at timestamp(0) without time zone,
    email character varying(255),
    email_verified_at timestamp(0) without time zone,
    password character varying(255),
    status character varying(32) DEFAULT 'pending_verification'::character varying NOT NULL,
    matricule character varying(255),
    job_title character varying(255),
    agency_code character varying(255),
    agency_name character varying(255),
    invited_by_user_id bigint,
    activated_at timestamp(0) without time zone,
    last_login_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agency_id bigint
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
-- Name: account_holds id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds ALTER COLUMN id SET DEFAULT nextval('public.account_holds_id_seq'::regclass);


--
-- Name: account_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products ALTER COLUMN id SET DEFAULT nextval('public.account_products_id_seq'::regclass);


--
-- Name: activity_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log ALTER COLUMN id SET DEFAULT nextval('public.activity_log_id_seq'::regclass);


--
-- Name: agencies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agencies ALTER COLUMN id SET DEFAULT nextval('public.agencies_id_seq'::regclass);


--
-- Name: api_idempotency_keys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys ALTER COLUMN id SET DEFAULT nextval('public.api_idempotency_keys_id_seq'::regclass);


--
-- Name: batch_procedures id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_procedures ALTER COLUMN id SET DEFAULT nextval('public.batch_procedures_id_seq'::regclass);


--
-- Name: batch_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs ALTER COLUMN id SET DEFAULT nextval('public.batch_runs_id_seq'::regclass);


--
-- Name: client_guarantors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors ALTER COLUMN id SET DEFAULT nextval('public.client_guarantors_id_seq'::regclass);


--
-- Name: client_identity_documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents ALTER COLUMN id SET DEFAULT nextval('public.client_identity_documents_id_seq'::regclass);


--
-- Name: client_kyc_reviews id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews ALTER COLUMN id SET DEFAULT nextval('public.client_kyc_reviews_id_seq'::regclass);


--
-- Name: client_notification_consents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents ALTER COLUMN id SET DEFAULT nextval('public.client_notification_consents_id_seq'::regclass);


--
-- Name: client_proxies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies ALTER COLUMN id SET DEFAULT nextval('public.client_proxies_id_seq'::regclass);


--
-- Name: clients id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients ALTER COLUMN id SET DEFAULT nextval('public.clients_id_seq'::regclass);


--
-- Name: collateral_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collateral_items ALTER COLUMN id SET DEFAULT nextval('public.collateral_items_id_seq'::regclass);


--
-- Name: collaterals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals ALTER COLUMN id SET DEFAULT nextval('public.collaterals_id_seq'::regclass);


--
-- Name: currencies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies ALTER COLUMN id SET DEFAULT nextval('public.currencies_id_seq'::regclass);


--
-- Name: customer_account_signatures id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures ALTER COLUMN id SET DEFAULT nextval('public.customer_account_signatures_id_seq'::regclass);


--
-- Name: customer_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts ALTER COLUMN id SET DEFAULT nextval('public.customer_accounts_id_seq'::regclass);


--
-- Name: dashboard_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_definitions ALTER COLUMN id SET DEFAULT nextval('public.dashboard_definitions_id_seq'::regclass);


--
-- Name: dashboard_widgets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets ALTER COLUMN id SET DEFAULT nextval('public.dashboard_widgets_id_seq'::regclass);


--
-- Name: delinquency_trackings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings ALTER COLUMN id SET DEFAULT nextval('public.delinquency_trackings_id_seq'::regclass);


--
-- Name: denominations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.denominations ALTER COLUMN id SET DEFAULT nextval('public.denominations_id_seq'::regclass);


--
-- Name: documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents ALTER COLUMN id SET DEFAULT nextval('public.documents_id_seq'::regclass);


--
-- Name: emf_ledger_account_mappings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings ALTER COLUMN id SET DEFAULT nextval('public.emf_ledger_account_mappings_id_seq'::regclass);


--
-- Name: emf_regulatory_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts ALTER COLUMN id SET DEFAULT nextval('public.emf_regulatory_accounts_id_seq'::regclass);


--
-- Name: exchange_rates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates ALTER COLUMN id SET DEFAULT nextval('public.exchange_rates_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: fx_authorizations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_authorizations ALTER COLUMN id SET DEFAULT nextval('public.fx_authorizations_id_seq'::regclass);


--
-- Name: fx_reconciliations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations ALTER COLUMN id SET DEFAULT nextval('public.fx_reconciliations_id_seq'::regclass);


--
-- Name: fx_stock_movements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements ALTER COLUMN id SET DEFAULT nextval('public.fx_stock_movements_id_seq'::regclass);


--
-- Name: fx_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions ALTER COLUMN id SET DEFAULT nextval('public.fx_transactions_id_seq'::regclass);


--
-- Name: hr_attendance_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_attendance_records ALTER COLUMN id SET DEFAULT nextval('public.hr_attendance_records_id_seq'::regclass);


--
-- Name: hr_contracts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts ALTER COLUMN id SET DEFAULT nextval('public.hr_contracts_id_seq'::regclass);


--
-- Name: hr_employee_agency_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_agency_history ALTER COLUMN id SET DEFAULT nextval('public.hr_employee_agency_history_id_seq'::regclass);


--
-- Name: hr_employee_documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_documents ALTER COLUMN id SET DEFAULT nextval('public.hr_employee_documents_id_seq'::regclass);


--
-- Name: hr_employees id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees ALTER COLUMN id SET DEFAULT nextval('public.hr_employees_id_seq'::regclass);


--
-- Name: hr_leave_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests ALTER COLUMN id SET DEFAULT nextval('public.hr_leave_requests_id_seq'::regclass);


--
-- Name: hr_payroll_formula_rates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_rates ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_formula_rates_id_seq'::regclass);


--
-- Name: hr_payroll_formula_sets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_formula_sets_id_seq'::regclass);


--
-- Name: hr_payroll_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_lines ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_lines_id_seq'::regclass);


--
-- Name: hr_payroll_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_runs_id_seq'::regclass);


--
-- Name: hr_payroll_slips id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_slips_id_seq'::regclass);


--
-- Name: hr_salary_advances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_salary_advances ALTER COLUMN id SET DEFAULT nextval('public.hr_salary_advances_id_seq'::regclass);


--
-- Name: hr_sanctions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_sanctions ALTER COLUMN id SET DEFAULT nextval('public.hr_sanctions_id_seq'::regclass);


--
-- Name: insurance_cancellations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations ALTER COLUMN id SET DEFAULT nextval('public.insurance_cancellations_id_seq'::regclass);


--
-- Name: insurance_claim_decisions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions ALTER COLUMN id SET DEFAULT nextval('public.insurance_claim_decisions_id_seq'::regclass);


--
-- Name: insurance_claim_documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_documents ALTER COLUMN id SET DEFAULT nextval('public.insurance_claim_documents_id_seq'::regclass);


--
-- Name: insurance_claim_evidence_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_evidence_configs ALTER COLUMN id SET DEFAULT nextval('public.insurance_claim_evidence_configs_id_seq'::regclass);


--
-- Name: insurance_claims id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims ALTER COLUMN id SET DEFAULT nextval('public.insurance_claims_id_seq'::regclass);


--
-- Name: insurance_endorsements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements ALTER COLUMN id SET DEFAULT nextval('public.insurance_endorsements_id_seq'::regclass);


--
-- Name: insurance_export_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_export_records ALTER COLUMN id SET DEFAULT nextval('public.insurance_export_records_id_seq'::regclass);


--
-- Name: insurance_partners id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners ALTER COLUMN id SET DEFAULT nextval('public.insurance_partners_id_seq'::regclass);


--
-- Name: insurance_premium_assessments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments ALTER COLUMN id SET DEFAULT nextval('public.insurance_premium_assessments_id_seq'::regclass);


--
-- Name: insurance_premium_payment_splits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits ALTER COLUMN id SET DEFAULT nextval('public.insurance_premium_payment_splits_id_seq'::regclass);


--
-- Name: insurance_premium_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments ALTER COLUMN id SET DEFAULT nextval('public.insurance_premium_payments_id_seq'::regclass);


--
-- Name: insurance_premium_schedules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules ALTER COLUMN id SET DEFAULT nextval('public.insurance_premium_schedules_id_seq'::regclass);


--
-- Name: insurance_product_coverages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_coverages ALTER COLUMN id SET DEFAULT nextval('public.insurance_product_coverages_id_seq'::regclass);


--
-- Name: insurance_product_rule_version_splits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_version_splits ALTER COLUMN id SET DEFAULT nextval('public.insurance_product_rule_version_splits_id_seq'::regclass);


--
-- Name: insurance_product_rule_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions ALTER COLUMN id SET DEFAULT nextval('public.insurance_product_rule_versions_id_seq'::regclass);


--
-- Name: insurance_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_products ALTER COLUMN id SET DEFAULT nextval('public.insurance_products_id_seq'::regclass);


--
-- Name: insurance_remittance_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches ALTER COLUMN id SET DEFAULT nextval('public.insurance_remittance_batches_id_seq'::regclass);


--
-- Name: insurance_remittance_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items ALTER COLUMN id SET DEFAULT nextval('public.insurance_remittance_items_id_seq'::regclass);


--
-- Name: insurance_subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions ALTER COLUMN id SET DEFAULT nextval('public.insurance_subscriptions_id_seq'::regclass);


--
-- Name: islamic_compliance_reviews id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews ALTER COLUMN id SET DEFAULT nextval('public.islamic_compliance_reviews_id_seq'::regclass);


--
-- Name: islamic_financed_assets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financed_assets ALTER COLUMN id SET DEFAULT nextval('public.islamic_financed_assets_id_seq'::regclass);


--
-- Name: islamic_financing_installments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments ALTER COLUMN id SET DEFAULT nextval('public.islamic_financing_installments_id_seq'::regclass);


--
-- Name: islamic_financings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings ALTER COLUMN id SET DEFAULT nextval('public.islamic_financings_id_seq'::regclass);


--
-- Name: islamic_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_products ALTER COLUMN id SET DEFAULT nextval('public.islamic_products_id_seq'::regclass);


--
-- Name: islamic_profit_sharing_terms id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_profit_sharing_terms ALTER COLUMN id SET DEFAULT nextval('public.islamic_profit_sharing_terms_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: journal_entries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries ALTER COLUMN id SET DEFAULT nextval('public.journal_entries_id_seq'::regclass);


--
-- Name: journal_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines ALTER COLUMN id SET DEFAULT nextval('public.journal_lines_id_seq'::regclass);


--
-- Name: ledger_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts ALTER COLUMN id SET DEFAULT nextval('public.ledger_accounts_id_seq'::regclass);


--
-- Name: loan_approvals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals ALTER COLUMN id SET DEFAULT nextval('public.loan_approvals_id_seq'::regclass);


--
-- Name: loan_arrears id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_arrears ALTER COLUMN id SET DEFAULT nextval('public.loan_arrears_id_seq'::regclass);


--
-- Name: loan_charge_assessments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments ALTER COLUMN id SET DEFAULT nextval('public.loan_charge_assessments_id_seq'::regclass);


--
-- Name: loan_disbursements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements ALTER COLUMN id SET DEFAULT nextval('public.loan_disbursements_id_seq'::regclass);


--
-- Name: loan_guarantee_obligations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations ALTER COLUMN id SET DEFAULT nextval('public.loan_guarantee_obligations_id_seq'::regclass);


--
-- Name: loan_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_products ALTER COLUMN id SET DEFAULT nextval('public.loan_products_id_seq'::regclass);


--
-- Name: loan_recovery_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts ALTER COLUMN id SET DEFAULT nextval('public.loan_recovery_accounts_id_seq'::regclass);


--
-- Name: loan_recovery_attempts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts ALTER COLUMN id SET DEFAULT nextval('public.loan_recovery_attempts_id_seq'::regclass);


--
-- Name: loan_repayment_allocations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayment_allocations ALTER COLUMN id SET DEFAULT nextval('public.loan_repayment_allocations_id_seq'::regclass);


--
-- Name: loan_repayments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments ALTER COLUMN id SET DEFAULT nextval('public.loan_repayments_id_seq'::regclass);


--
-- Name: loan_schedule_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_lines ALTER COLUMN id SET DEFAULT nextval('public.loan_schedule_lines_id_seq'::regclass);


--
-- Name: loan_schedule_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_snapshots ALTER COLUMN id SET DEFAULT nextval('public.loan_schedule_snapshots_id_seq'::regclass);


--
-- Name: loan_status_transitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions ALTER COLUMN id SET DEFAULT nextval('public.loan_status_transitions_id_seq'::regclass);


--
-- Name: loan_transfers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers ALTER COLUMN id SET DEFAULT nextval('public.loan_transfers_id_seq'::regclass);


--
-- Name: loans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans ALTER COLUMN id SET DEFAULT nextval('public.loans_id_seq'::regclass);


--
-- Name: media id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media ALTER COLUMN id SET DEFAULT nextval('public.media_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notification_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries ALTER COLUMN id SET DEFAULT nextval('public.notification_deliveries_id_seq'::regclass);


--
-- Name: notification_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates ALTER COLUMN id SET DEFAULT nextval('public.notification_templates_id_seq'::regclass);


--
-- Name: operation_account_mappings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings ALTER COLUMN id SET DEFAULT nextval('public.operation_account_mappings_id_seq'::regclass);


--
-- Name: operation_codes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_codes ALTER COLUMN id SET DEFAULT nextval('public.operation_codes_id_seq'::regclass);


--
-- Name: otp_challenges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_challenges ALTER COLUMN id SET DEFAULT nextval('public.otp_challenges_id_seq'::regclass);


--
-- Name: otp_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_deliveries ALTER COLUMN id SET DEFAULT nextval('public.otp_deliveries_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: reference_sequences id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reference_sequences ALTER COLUMN id SET DEFAULT nextval('public.reference_sequences_id_seq'::regclass);


--
-- Name: regulatory_sources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_sources ALTER COLUMN id SET DEFAULT nextval('public.regulatory_sources_id_seq'::regclass);


--
-- Name: report_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_definitions ALTER COLUMN id SET DEFAULT nextval('public.report_definitions_id_seq'::regclass);


--
-- Name: report_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs ALTER COLUMN id SET DEFAULT nextval('public.report_runs_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: sectors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sectors ALTER COLUMN id SET DEFAULT nextval('public.sectors_id_seq'::regclass);


--
-- Name: sms_messages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_messages ALTER COLUMN id SET DEFAULT nextval('public.sms_messages_id_seq'::regclass);


--
-- Name: staff_agency_assignments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments ALTER COLUMN id SET DEFAULT nextval('public.staff_agency_assignments_id_seq'::regclass);


--
-- Name: sub_sectors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors ALTER COLUMN id SET DEFAULT nextval('public.sub_sectors_id_seq'::regclass);


--
-- Name: teller_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions ALTER COLUMN id SET DEFAULT nextval('public.teller_sessions_id_seq'::regclass);


--
-- Name: teller_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions ALTER COLUMN id SET DEFAULT nextval('public.teller_transactions_id_seq'::regclass);


--
-- Name: till_currency_balances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_currency_balances ALTER COLUMN id SET DEFAULT nextval('public.till_currency_balances_id_seq'::regclass);


--
-- Name: till_reconciliation_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliation_lines ALTER COLUMN id SET DEFAULT nextval('public.till_reconciliation_lines_id_seq'::regclass);


--
-- Name: till_reconciliations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations ALTER COLUMN id SET DEFAULT nextval('public.till_reconciliations_id_seq'::regclass);


--
-- Name: tills id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills ALTER COLUMN id SET DEFAULT nextval('public.tills_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: account_holds account_holds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds
    ADD CONSTRAINT account_holds_pkey PRIMARY KEY (id);


--
-- Name: account_holds account_holds_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds
    ADD CONSTRAINT account_holds_public_id_unique UNIQUE (public_id);


--
-- Name: account_products account_products_agency_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products
    ADD CONSTRAINT account_products_agency_id_code_unique UNIQUE (agency_id, code);


--
-- Name: account_products account_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products
    ADD CONSTRAINT account_products_pkey PRIMARY KEY (id);


--
-- Name: account_products account_products_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products
    ADD CONSTRAINT account_products_public_id_unique UNIQUE (public_id);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: agencies agencies_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agencies
    ADD CONSTRAINT agencies_code_unique UNIQUE (code);


--
-- Name: agencies agencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agencies
    ADD CONSTRAINT agencies_pkey PRIMARY KEY (id);


--
-- Name: agencies agencies_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agencies
    ADD CONSTRAINT agencies_public_id_unique UNIQUE (public_id);


--
-- Name: api_idempotency_keys api_idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys
    ADD CONSTRAINT api_idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: api_idempotency_keys api_idempotency_keys_scope_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys
    ADD CONSTRAINT api_idempotency_keys_scope_hash_unique UNIQUE (scope_hash);


--
-- Name: batch_procedures batch_procedures_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_procedures
    ADD CONSTRAINT batch_procedures_code_unique UNIQUE (code);


--
-- Name: batch_procedures batch_procedures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_procedures
    ADD CONSTRAINT batch_procedures_pkey PRIMARY KEY (id);


--
-- Name: batch_procedures batch_procedures_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_procedures
    ADD CONSTRAINT batch_procedures_public_id_unique UNIQUE (public_id);


--
-- Name: batch_runs batch_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_pkey PRIMARY KEY (id);


--
-- Name: batch_runs batch_runs_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_public_id_unique UNIQUE (public_id);


--
-- Name: batch_runs batch_runs_scope_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_scope_hash_unique UNIQUE (scope_hash);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: client_guarantors client_guarantors_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: client_guarantors client_guarantors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_pkey PRIMARY KEY (id);


--
-- Name: client_guarantors client_guarantors_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_public_id_unique UNIQUE (public_id);


--
-- Name: client_identity_documents client_identity_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_pkey PRIMARY KEY (id);


--
-- Name: client_identity_documents client_identity_documents_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_public_id_unique UNIQUE (public_id);


--
-- Name: client_kyc_reviews client_kyc_reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews
    ADD CONSTRAINT client_kyc_reviews_pkey PRIMARY KEY (id);


--
-- Name: client_kyc_reviews client_kyc_reviews_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews
    ADD CONSTRAINT client_kyc_reviews_public_id_unique UNIQUE (public_id);


--
-- Name: client_notification_consents client_notification_consents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT client_notification_consents_pkey PRIMARY KEY (id);


--
-- Name: client_notification_consents client_notification_consents_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT client_notification_consents_public_id_unique UNIQUE (public_id);


--
-- Name: client_proxies client_proxies_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: client_proxies client_proxies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_pkey PRIMARY KEY (id);


--
-- Name: client_proxies client_proxies_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_public_id_unique UNIQUE (public_id);


--
-- Name: clients clients_agency_id_client_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_agency_id_client_reference_unique UNIQUE (agency_id, client_reference);


--
-- Name: clients clients_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


--
-- Name: clients clients_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_public_id_unique UNIQUE (public_id);


--
-- Name: collateral_items collateral_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collateral_items
    ADD CONSTRAINT collateral_items_pkey PRIMARY KEY (id);


--
-- Name: collateral_items collateral_items_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collateral_items
    ADD CONSTRAINT collateral_items_public_id_unique UNIQUE (public_id);


--
-- Name: collaterals collaterals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_pkey PRIMARY KEY (id);


--
-- Name: collaterals collaterals_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_public_id_unique UNIQUE (public_id);


--
-- Name: currencies currencies_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_code_unique UNIQUE (code);


--
-- Name: currencies currencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_pkey PRIMARY KEY (id);


--
-- Name: customer_account_signatures customer_account_signatures_document_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_document_id_unique UNIQUE (document_id);


--
-- Name: customer_account_signatures customer_account_signatures_id_account_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_id_account_unique UNIQUE (id, customer_account_id);


--
-- Name: customer_account_signatures customer_account_signatures_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: customer_account_signatures customer_account_signatures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_pkey PRIMARY KEY (id);


--
-- Name: customer_account_signatures customer_account_signatures_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_public_id_unique UNIQUE (public_id);


--
-- Name: customer_accounts customer_accounts_account_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_account_number_unique UNIQUE (account_number);


--
-- Name: customer_accounts customer_accounts_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: customer_accounts customer_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_pkey PRIMARY KEY (id);


--
-- Name: customer_accounts customer_accounts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_public_id_unique UNIQUE (public_id);


--
-- Name: dashboard_definitions dashboard_definitions_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_code_unique UNIQUE (code);


--
-- Name: dashboard_definitions dashboard_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_pkey PRIMARY KEY (id);


--
-- Name: dashboard_definitions dashboard_definitions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_public_id_unique UNIQUE (public_id);


--
-- Name: dashboard_widgets dashboard_widgets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_pkey PRIMARY KEY (id);


--
-- Name: delinquency_trackings delinquency_trackings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_pkey PRIMARY KEY (id);


--
-- Name: delinquency_trackings delinquency_trackings_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_public_id_unique UNIQUE (public_id);


--
-- Name: denominations denominations_currency_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.denominations
    ADD CONSTRAINT denominations_currency_code_unique UNIQUE (currency, code);


--
-- Name: denominations denominations_currency_value_minor_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.denominations
    ADD CONSTRAINT denominations_currency_value_minor_unique UNIQUE (currency, value_minor);


--
-- Name: denominations denominations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.denominations
    ADD CONSTRAINT denominations_pkey PRIMARY KEY (id);


--
-- Name: denominations denominations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.denominations
    ADD CONSTRAINT denominations_public_id_unique UNIQUE (public_id);


--
-- Name: documents documents_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: documents documents_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_public_id_unique UNIQUE (public_id);


--
-- Name: emf_ledger_account_mappings emf_ledger_account_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings
    ADD CONSTRAINT emf_ledger_account_mappings_pkey PRIMARY KEY (id);


--
-- Name: emf_ledger_account_mappings emf_ledger_account_mappings_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings
    ADD CONSTRAINT emf_ledger_account_mappings_public_id_unique UNIQUE (public_id);


--
-- Name: emf_regulatory_accounts emf_regulatory_accounts_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts
    ADD CONSTRAINT emf_regulatory_accounts_code_unique UNIQUE (code);


--
-- Name: emf_regulatory_accounts emf_regulatory_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts
    ADD CONSTRAINT emf_regulatory_accounts_pkey PRIMARY KEY (id);


--
-- Name: emf_regulatory_accounts emf_regulatory_accounts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts
    ADD CONSTRAINT emf_regulatory_accounts_public_id_unique UNIQUE (public_id);


--
-- Name: exchange_rates exchange_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_pkey PRIMARY KEY (id);


--
-- Name: exchange_rates exchange_rates_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_public_id_unique UNIQUE (public_id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: fx_authorizations fx_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_authorizations
    ADD CONSTRAINT fx_authorizations_pkey PRIMARY KEY (id);


--
-- Name: fx_authorizations fx_authorizations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_authorizations
    ADD CONSTRAINT fx_authorizations_public_id_unique UNIQUE (public_id);


--
-- Name: fx_reconciliations fx_reconciliations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT fx_reconciliations_pkey PRIMARY KEY (id);


--
-- Name: fx_reconciliations fx_reconciliations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT fx_reconciliations_public_id_unique UNIQUE (public_id);


--
-- Name: fx_stock_movements fx_stock_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_pkey PRIMARY KEY (id);


--
-- Name: fx_stock_movements fx_stock_movements_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_public_id_unique UNIQUE (public_id);


--
-- Name: fx_transactions fx_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_pkey PRIMARY KEY (id);


--
-- Name: fx_transactions fx_transactions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_public_id_unique UNIQUE (public_id);


--
-- Name: fx_transactions fx_transactions_register_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_register_number_unique UNIQUE (register_number);


--
-- Name: fx_transactions fx_transactions_slip_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_slip_number_unique UNIQUE (slip_number);


--
-- Name: fx_transactions fx_transactions_transaction_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_transaction_number_unique UNIQUE (transaction_number);


--
-- Name: hr_attendance_records hr_attendance_records_hr_employee_id_attendance_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_attendance_records
    ADD CONSTRAINT hr_attendance_records_hr_employee_id_attendance_date_unique UNIQUE (hr_employee_id, attendance_date);


--
-- Name: hr_attendance_records hr_attendance_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_attendance_records
    ADD CONSTRAINT hr_attendance_records_pkey PRIMARY KEY (id);


--
-- Name: hr_attendance_records hr_attendance_records_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_attendance_records
    ADD CONSTRAINT hr_attendance_records_public_id_unique UNIQUE (public_id);


--
-- Name: hr_contracts hr_contracts_contract_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_contract_number_unique UNIQUE (contract_number);


--
-- Name: hr_contracts hr_contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_pkey PRIMARY KEY (id);


--
-- Name: hr_contracts hr_contracts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_public_id_unique UNIQUE (public_id);


--
-- Name: hr_employee_agency_history hr_employee_agency_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_agency_history
    ADD CONSTRAINT hr_employee_agency_history_pkey PRIMARY KEY (id);


--
-- Name: hr_employee_agency_history hr_employee_agency_history_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_agency_history
    ADD CONSTRAINT hr_employee_agency_history_public_id_unique UNIQUE (public_id);


--
-- Name: hr_employee_documents hr_employee_documents_hr_employee_id_document_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_documents
    ADD CONSTRAINT hr_employee_documents_hr_employee_id_document_id_unique UNIQUE (hr_employee_id, document_id);


--
-- Name: hr_employee_documents hr_employee_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_documents
    ADD CONSTRAINT hr_employee_documents_pkey PRIMARY KEY (id);


--
-- Name: hr_employees hr_employees_employee_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_employee_number_unique UNIQUE (employee_number);


--
-- Name: hr_employees hr_employees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_pkey PRIMARY KEY (id);


--
-- Name: hr_employees hr_employees_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_public_id_unique UNIQUE (public_id);


--
-- Name: hr_leave_requests hr_leave_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests
    ADD CONSTRAINT hr_leave_requests_pkey PRIMARY KEY (id);


--
-- Name: hr_leave_requests hr_leave_requests_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests
    ADD CONSTRAINT hr_leave_requests_public_id_unique UNIQUE (public_id);


--
-- Name: hr_payroll_formula_rates hr_payroll_formula_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_rates
    ADD CONSTRAINT hr_payroll_formula_rates_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_formula_sets hr_payroll_formula_sets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT hr_payroll_formula_sets_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_formula_sets hr_payroll_formula_sets_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT hr_payroll_formula_sets_public_id_unique UNIQUE (public_id);


--
-- Name: hr_payroll_lines hr_payroll_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_lines
    ADD CONSTRAINT hr_payroll_lines_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_runs hr_payroll_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_runs hr_payroll_runs_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_public_id_unique UNIQUE (public_id);


--
-- Name: hr_payroll_slips hr_payroll_slips_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_slips hr_payroll_slips_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_public_id_unique UNIQUE (public_id);


--
-- Name: hr_payroll_slips hr_payroll_slips_slip_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_slip_number_unique UNIQUE (slip_number);


--
-- Name: hr_salary_advances hr_salary_advances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_salary_advances
    ADD CONSTRAINT hr_salary_advances_pkey PRIMARY KEY (id);


--
-- Name: hr_salary_advances hr_salary_advances_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_salary_advances
    ADD CONSTRAINT hr_salary_advances_public_id_unique UNIQUE (public_id);


--
-- Name: hr_sanctions hr_sanctions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_sanctions
    ADD CONSTRAINT hr_sanctions_pkey PRIMARY KEY (id);


--
-- Name: hr_sanctions hr_sanctions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_sanctions
    ADD CONSTRAINT hr_sanctions_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_cancellations insurance_cancellations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_pkey PRIMARY KEY (id);


--
-- Name: insurance_cancellations insurance_cancellations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_claim_decisions insurance_claim_decisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions
    ADD CONSTRAINT insurance_claim_decisions_pkey PRIMARY KEY (id);


--
-- Name: insurance_claim_decisions insurance_claim_decisions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions
    ADD CONSTRAINT insurance_claim_decisions_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_claim_documents insurance_claim_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_documents
    ADD CONSTRAINT insurance_claim_documents_pkey PRIMARY KEY (id);


--
-- Name: insurance_claim_evidence_configs insurance_claim_evidence_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_evidence_configs
    ADD CONSTRAINT insurance_claim_evidence_configs_pkey PRIMARY KEY (id);


--
-- Name: insurance_claim_evidence_configs insurance_claim_evidence_configs_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_evidence_configs
    ADD CONSTRAINT insurance_claim_evidence_configs_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_claims insurance_claims_claim_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_claim_number_unique UNIQUE (claim_number);


--
-- Name: insurance_claims insurance_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_pkey PRIMARY KEY (id);


--
-- Name: insurance_claims insurance_claims_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_endorsements insurance_endorsements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements
    ADD CONSTRAINT insurance_endorsements_pkey PRIMARY KEY (id);


--
-- Name: insurance_endorsements insurance_endorsements_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements
    ADD CONSTRAINT insurance_endorsements_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_export_records insurance_export_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_export_records
    ADD CONSTRAINT insurance_export_records_pkey PRIMARY KEY (id);


--
-- Name: insurance_export_records insurance_export_records_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_export_records
    ADD CONSTRAINT insurance_export_records_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_partners insurance_partners_agency_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners
    ADD CONSTRAINT insurance_partners_agency_id_code_unique UNIQUE (agency_id, code);


--
-- Name: insurance_partners insurance_partners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners
    ADD CONSTRAINT insurance_partners_pkey PRIMARY KEY (id);


--
-- Name: insurance_partners insurance_partners_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners
    ADD CONSTRAINT insurance_partners_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_premium_assessments insurance_premium_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_pkey PRIMARY KEY (id);


--
-- Name: insurance_premium_assessments insurance_premium_assessments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_premium_payment_splits insurance_premium_payment_splits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT insurance_premium_payment_splits_pkey PRIMARY KEY (id);


--
-- Name: insurance_premium_payment_splits insurance_premium_payment_splits_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT insurance_premium_payment_splits_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_premium_payments insurance_premium_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_pkey PRIMARY KEY (id);


--
-- Name: insurance_premium_payments insurance_premium_payments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_premium_schedules insurance_premium_schedules_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: insurance_premium_schedules insurance_premium_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_pkey PRIMARY KEY (id);


--
-- Name: insurance_premium_schedules insurance_premium_schedules_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_product_coverages insurance_product_coverages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_coverages
    ADD CONSTRAINT insurance_product_coverages_pkey PRIMARY KEY (id);


--
-- Name: insurance_product_rule_version_splits insurance_product_rule_version_splits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_version_splits
    ADD CONSTRAINT insurance_product_rule_version_splits_pkey PRIMARY KEY (id);


--
-- Name: insurance_product_rule_versions insurance_product_rule_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT insurance_product_rule_versions_pkey PRIMARY KEY (id);


--
-- Name: insurance_product_rule_versions insurance_product_rule_versions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT insurance_product_rule_versions_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_products insurance_products_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_products
    ADD CONSTRAINT insurance_products_code_unique UNIQUE (code);


--
-- Name: insurance_products insurance_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_products
    ADD CONSTRAINT insurance_products_pkey PRIMARY KEY (id);


--
-- Name: insurance_products insurance_products_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_products
    ADD CONSTRAINT insurance_products_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_remittance_batches insurance_remittance_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_pkey PRIMARY KEY (id);


--
-- Name: insurance_remittance_batches insurance_remittance_batches_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_remittance_items insurance_remittance_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_pkey PRIMARY KEY (id);


--
-- Name: insurance_remittance_items insurance_remittance_items_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_subscriptions insurance_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: insurance_subscriptions insurance_subscriptions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_subscriptions insurance_subscriptions_subscription_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_subscription_number_unique UNIQUE (subscription_number);


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_pkey PRIMARY KEY (id);


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_public_id_unique UNIQUE (public_id);


--
-- Name: islamic_financed_assets islamic_financed_assets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financed_assets
    ADD CONSTRAINT islamic_financed_assets_pkey PRIMARY KEY (id);


--
-- Name: islamic_financed_assets islamic_financed_assets_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financed_assets
    ADD CONSTRAINT islamic_financed_assets_public_id_unique UNIQUE (public_id);


--
-- Name: islamic_financing_installments islamic_financing_installments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments
    ADD CONSTRAINT islamic_financing_installments_pkey PRIMARY KEY (id);


--
-- Name: islamic_financing_installments islamic_financing_installments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments
    ADD CONSTRAINT islamic_financing_installments_public_id_unique UNIQUE (public_id);


--
-- Name: islamic_financings islamic_financings_contract_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_contract_number_unique UNIQUE (contract_number);


--
-- Name: islamic_financings islamic_financings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_pkey PRIMARY KEY (id);


--
-- Name: islamic_financings islamic_financings_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_public_id_unique UNIQUE (public_id);


--
-- Name: islamic_products islamic_products_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_products
    ADD CONSTRAINT islamic_products_code_unique UNIQUE (code);


--
-- Name: islamic_products islamic_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_products
    ADD CONSTRAINT islamic_products_pkey PRIMARY KEY (id);


--
-- Name: islamic_products islamic_products_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_products
    ADD CONSTRAINT islamic_products_public_id_unique UNIQUE (public_id);


--
-- Name: islamic_profit_sharing_terms islamic_profit_sharing_terms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_profit_sharing_terms
    ADD CONSTRAINT islamic_profit_sharing_terms_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: journal_entries journal_entries_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: journal_entries journal_entries_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: journal_entries journal_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_pkey PRIMARY KEY (id);


--
-- Name: journal_entries journal_entries_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_public_id_unique UNIQUE (public_id);


--
-- Name: journal_entries journal_entries_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_reference_unique UNIQUE (reference);


--
-- Name: journal_lines journal_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_pkey PRIMARY KEY (id);


--
-- Name: journal_lines journal_lines_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_public_id_unique UNIQUE (public_id);


--
-- Name: ledger_accounts ledger_accounts_agency_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_agency_code_unique UNIQUE (agency_id, code);


--
-- Name: ledger_accounts ledger_accounts_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: ledger_accounts ledger_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_pkey PRIMARY KEY (id);


--
-- Name: ledger_accounts ledger_accounts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_public_id_unique UNIQUE (public_id);


--
-- Name: loan_approvals loan_approvals_loan_id_step_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_loan_id_step_unique UNIQUE (loan_id, step);


--
-- Name: loan_approvals loan_approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_pkey PRIMARY KEY (id);


--
-- Name: loan_approvals loan_approvals_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_public_id_unique UNIQUE (public_id);


--
-- Name: loan_arrears loan_arrears_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_arrears
    ADD CONSTRAINT loan_arrears_pkey PRIMARY KEY (id);


--
-- Name: loan_arrears loan_arrears_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_arrears
    ADD CONSTRAINT loan_arrears_public_id_unique UNIQUE (public_id);


--
-- Name: loan_charge_assessments loan_charge_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_pkey PRIMARY KEY (id);


--
-- Name: loan_charge_assessments loan_charge_assessments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_public_id_unique UNIQUE (public_id);


--
-- Name: loan_disbursements loan_disbursements_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: loan_disbursements loan_disbursements_journal_entry_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_journal_entry_id_unique UNIQUE (journal_entry_id);


--
-- Name: loan_disbursements loan_disbursements_loan_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_loan_id_unique UNIQUE (loan_id);


--
-- Name: loan_disbursements loan_disbursements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_pkey PRIMARY KEY (id);


--
-- Name: loan_disbursements loan_disbursements_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_public_id_unique UNIQUE (public_id);


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_pkey PRIMARY KEY (id);


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_public_id_unique UNIQUE (public_id);


--
-- Name: loan_products loan_products_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_products
    ADD CONSTRAINT loan_products_code_unique UNIQUE (code);


--
-- Name: loan_products loan_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_products
    ADD CONSTRAINT loan_products_pkey PRIMARY KEY (id);


--
-- Name: loan_products loan_products_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_products
    ADD CONSTRAINT loan_products_public_id_unique UNIQUE (public_id);


--
-- Name: loan_recovery_accounts loan_recovery_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts
    ADD CONSTRAINT loan_recovery_accounts_pkey PRIMARY KEY (id);


--
-- Name: loan_recovery_accounts loan_recovery_accounts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts
    ADD CONSTRAINT loan_recovery_accounts_public_id_unique UNIQUE (public_id);


--
-- Name: loan_recovery_attempts loan_recovery_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_pkey PRIMARY KEY (id);


--
-- Name: loan_recovery_attempts loan_recovery_attempts_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_public_id_unique UNIQUE (public_id);


--
-- Name: loan_repayment_allocations loan_repayment_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayment_allocations
    ADD CONSTRAINT loan_repayment_allocations_pkey PRIMARY KEY (id);


--
-- Name: loan_repayments loan_repayments_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: loan_repayments loan_repayments_journal_entry_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_journal_entry_id_unique UNIQUE (journal_entry_id);


--
-- Name: loan_repayments loan_repayments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_pkey PRIMARY KEY (id);


--
-- Name: loan_repayments loan_repayments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_public_id_unique UNIQUE (public_id);


--
-- Name: loan_schedule_lines loan_schedule_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_lines
    ADD CONSTRAINT loan_schedule_lines_pkey PRIMARY KEY (id);


--
-- Name: loan_schedule_snapshots loan_schedule_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_snapshots
    ADD CONSTRAINT loan_schedule_snapshots_pkey PRIMARY KEY (id);


--
-- Name: loan_schedule_snapshots loan_schedule_snapshots_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_snapshots
    ADD CONSTRAINT loan_schedule_snapshots_public_id_unique UNIQUE (public_id);


--
-- Name: loan_status_transitions loan_status_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_pkey PRIMARY KEY (id);


--
-- Name: loan_status_transitions loan_status_transitions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_public_id_unique UNIQUE (public_id);


--
-- Name: loan_transfers loan_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_pkey PRIMARY KEY (id);


--
-- Name: loan_transfers loan_transfers_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_public_id_unique UNIQUE (public_id);


--
-- Name: loans loans_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: loans loans_loan_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_loan_number_unique UNIQUE (loan_number);


--
-- Name: loans loans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_pkey PRIMARY KEY (id);


--
-- Name: loans loans_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_public_id_unique UNIQUE (public_id);


--
-- Name: media media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_pkey PRIMARY KEY (id);


--
-- Name: media media_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_uuid_unique UNIQUE (uuid);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notification_deliveries notification_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries
    ADD CONSTRAINT notification_deliveries_pkey PRIMARY KEY (id);


--
-- Name: notification_deliveries notification_deliveries_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries
    ADD CONSTRAINT notification_deliveries_public_id_unique UNIQUE (public_id);


--
-- Name: notification_templates notification_templates_code_version_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_code_version_unique UNIQUE (code, version);


--
-- Name: notification_templates notification_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_pkey PRIMARY KEY (id);


--
-- Name: notification_templates notification_templates_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_public_id_unique UNIQUE (public_id);


--
-- Name: operation_account_mappings operation_account_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings
    ADD CONSTRAINT operation_account_mappings_pkey PRIMARY KEY (id);


--
-- Name: operation_account_mappings operation_account_mappings_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings
    ADD CONSTRAINT operation_account_mappings_public_id_unique UNIQUE (public_id);


--
-- Name: operation_codes operation_codes_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_codes
    ADD CONSTRAINT operation_codes_code_unique UNIQUE (code);


--
-- Name: operation_codes operation_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_codes
    ADD CONSTRAINT operation_codes_pkey PRIMARY KEY (id);


--
-- Name: operation_codes operation_codes_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_codes
    ADD CONSTRAINT operation_codes_public_id_unique UNIQUE (public_id);


--
-- Name: otp_challenges otp_challenges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_challenges
    ADD CONSTRAINT otp_challenges_pkey PRIMARY KEY (id);


--
-- Name: otp_deliveries otp_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_deliveries
    ADD CONSTRAINT otp_deliveries_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: reference_sequences reference_sequences_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reference_sequences
    ADD CONSTRAINT reference_sequences_key_unique UNIQUE (key);


--
-- Name: reference_sequences reference_sequences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reference_sequences
    ADD CONSTRAINT reference_sequences_pkey PRIMARY KEY (id);


--
-- Name: regulatory_sources regulatory_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_sources
    ADD CONSTRAINT regulatory_sources_pkey PRIMARY KEY (id);


--
-- Name: regulatory_sources regulatory_sources_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_sources
    ADD CONSTRAINT regulatory_sources_public_id_unique UNIQUE (public_id);


--
-- Name: report_definitions report_definitions_code_version_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_code_version_unique UNIQUE (code, version);


--
-- Name: report_definitions report_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_pkey PRIMARY KEY (id);


--
-- Name: report_definitions report_definitions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_public_id_unique UNIQUE (public_id);


--
-- Name: report_runs report_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_pkey PRIMARY KEY (id);


--
-- Name: report_runs report_runs_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_public_id_unique UNIQUE (public_id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sectors sectors_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sectors
    ADD CONSTRAINT sectors_code_unique UNIQUE (code);


--
-- Name: sectors sectors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sectors
    ADD CONSTRAINT sectors_pkey PRIMARY KEY (id);


--
-- Name: sectors sectors_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sectors
    ADD CONSTRAINT sectors_public_id_unique UNIQUE (public_id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sms_messages sms_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_messages
    ADD CONSTRAINT sms_messages_pkey PRIMARY KEY (id);


--
-- Name: sms_messages sms_messages_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_messages
    ADD CONSTRAINT sms_messages_public_id_unique UNIQUE (public_id);


--
-- Name: staff_agency_assignments staff_agency_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT staff_agency_assignments_pkey PRIMARY KEY (id);


--
-- Name: staff_agency_assignments staff_agency_assignments_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT staff_agency_assignments_public_id_unique UNIQUE (public_id);


--
-- Name: staff_agency_assignments staff_primary_assignment_no_overlap; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT staff_primary_assignment_no_overlap EXCLUDE USING gist (user_id WITH =, daterange(starts_on, COALESCE(ends_on, 'infinity'::date), '[]'::text) WITH &&) WHERE (((is_primary = true) AND ((status)::text = 'active'::text)));


--
-- Name: sub_sectors sub_sectors_id_sector_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors
    ADD CONSTRAINT sub_sectors_id_sector_unique UNIQUE (id, sector_id);


--
-- Name: sub_sectors sub_sectors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors
    ADD CONSTRAINT sub_sectors_pkey PRIMARY KEY (id);


--
-- Name: sub_sectors sub_sectors_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors
    ADD CONSTRAINT sub_sectors_public_id_unique UNIQUE (public_id);


--
-- Name: sub_sectors sub_sectors_sector_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors
    ADD CONSTRAINT sub_sectors_sector_id_code_unique UNIQUE (sector_id, code);


--
-- Name: teller_sessions teller_sessions_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: teller_sessions teller_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_pkey PRIMARY KEY (id);


--
-- Name: teller_sessions teller_sessions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_public_id_unique UNIQUE (public_id);


--
-- Name: teller_transactions teller_transactions_event_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_event_number_unique UNIQUE (event_number);


--
-- Name: teller_transactions teller_transactions_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: teller_transactions teller_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_pkey PRIMARY KEY (id);


--
-- Name: teller_transactions teller_transactions_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_public_id_unique UNIQUE (public_id);


--
-- Name: teller_transactions teller_transactions_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_reference_unique UNIQUE (reference);


--
-- Name: till_currency_balances till_currency_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_currency_balances
    ADD CONSTRAINT till_currency_balances_pkey PRIMARY KEY (id);


--
-- Name: till_currency_balances till_currency_balances_till_id_currency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_currency_balances
    ADD CONSTRAINT till_currency_balances_till_id_currency_unique UNIQUE (till_id, currency);


--
-- Name: till_reconciliation_lines till_reconciliation_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliation_lines
    ADD CONSTRAINT till_reconciliation_lines_pkey PRIMARY KEY (id);


--
-- Name: till_reconciliations till_reconciliations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_pkey PRIMARY KEY (id);


--
-- Name: till_reconciliations till_reconciliations_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_public_id_unique UNIQUE (public_id);


--
-- Name: tills tills_agency_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_agency_id_code_unique UNIQUE (agency_id, code);


--
-- Name: tills tills_id_agency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_id_agency_unique UNIQUE (id, agency_id);


--
-- Name: tills tills_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_pkey PRIMARY KEY (id);


--
-- Name: tills tills_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_public_id_unique UNIQUE (public_id);


--
-- Name: insurance_claim_documents uniq_claim_document; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_documents
    ADD CONSTRAINT uniq_claim_document UNIQUE (insurance_claim_id, document_id);


--
-- Name: client_notification_consents uniq_client_consent_axis; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT uniq_client_consent_axis UNIQUE (client_id, channel, category, language);


--
-- Name: dashboard_widgets uniq_dashboard_widget; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT uniq_dashboard_widget UNIQUE (dashboard_definition_id, code);


--
-- Name: emf_ledger_account_mappings uniq_emf_ledger_mapping; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings
    ADD CONSTRAINT uniq_emf_ledger_mapping UNIQUE (emf_regulatory_account_id, ledger_account_id);


--
-- Name: insurance_claim_evidence_configs uniq_evidence_config; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_evidence_configs
    ADD CONSTRAINT uniq_evidence_config UNIQUE (insurance_product_id, claim_type, document_type);


--
-- Name: exchange_rates uniq_fx_rate_day; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT uniq_fx_rate_day UNIQUE (base_currency, quote_currency, effective_on);


--
-- Name: fx_reconciliations uniq_fx_reco_axis; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT uniq_fx_reco_axis UNIQUE (till_id, business_date, currency);


--
-- Name: hr_payroll_formula_sets uniq_hr_payroll_formula_code_version; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT uniq_hr_payroll_formula_code_version UNIQUE (code, version);


--
-- Name: insurance_product_coverages uniq_insurance_product_coverage; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_coverages
    ADD CONSTRAINT uniq_insurance_product_coverage UNIQUE (insurance_product_id, coverage_code);


--
-- Name: islamic_financing_installments uniq_islamic_installment_number; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments
    ADD CONSTRAINT uniq_islamic_installment_number UNIQUE (islamic_financing_id, installment_number);


--
-- Name: loan_recovery_accounts uniq_loan_recovery_account; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts
    ADD CONSTRAINT uniq_loan_recovery_account UNIQUE (loan_id, customer_account_id);


--
-- Name: insurance_premium_payment_splits uniq_premium_payment_split_type; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT uniq_premium_payment_split_type UNIQUE (insurance_premium_payment_id, split_type);


--
-- Name: insurance_product_rule_versions uniq_product_rule_version; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT uniq_product_rule_version UNIQUE (insurance_product_id, version_number);


--
-- Name: till_reconciliation_lines uniq_reconciliation_denomination; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliation_lines
    ADD CONSTRAINT uniq_reconciliation_denomination UNIQUE (till_reconciliation_id, denomination_id);


--
-- Name: regulatory_sources uniq_regulatory_source_axis; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_sources
    ADD CONSTRAINT uniq_regulatory_source_axis UNIQUE (authority, reference, effective_date);


--
-- Name: insurance_remittance_items uniq_remittance_payment_split; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT uniq_remittance_payment_split UNIQUE (insurance_remittance_batch_id, insurance_premium_payment_id, split_type);


--
-- Name: insurance_product_rule_version_splits uniq_rule_split_type; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_version_splits
    ADD CONSTRAINT uniq_rule_split_type UNIQUE (insurance_product_rule_version_id, split_type);


--
-- Name: loan_schedule_lines uniq_schedule_installment; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_lines
    ADD CONSTRAINT uniq_schedule_installment UNIQUE (loan_schedule_snapshot_id, installment_number);


--
-- Name: staff_agency_assignments uniq_staff_agency_start; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT uniq_staff_agency_start UNIQUE (user_id, agency_id, starts_on);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_matricule_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_matricule_unique UNIQUE (matricule);


--
-- Name: users users_phone_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_number_unique UNIQUE (phone_number);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_public_id_unique UNIQUE (public_id);


--
-- Name: account_holds_customer_account_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_holds_customer_account_id_status_index ON public.account_holds USING btree (customer_account_id, status);


--
-- Name: account_holds_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_holds_status_index ON public.account_holds USING btree (status);


--
-- Name: account_products_account_family_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_products_account_family_index ON public.account_products USING btree (account_family);


--
-- Name: account_products_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_products_status_index ON public.account_products USING btree (status);


--
-- Name: activity_log_log_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_log_log_name_index ON public.activity_log USING btree (log_name);


--
-- Name: agencies_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agencies_status_index ON public.agencies USING btree (status);


--
-- Name: api_idempotency_keys_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_idempotency_keys_expires_at_index ON public.api_idempotency_keys USING btree (expires_at);


--
-- Name: api_idempotency_keys_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_idempotency_keys_key_index ON public.api_idempotency_keys USING btree (key);


--
-- Name: api_idempotency_keys_method_path_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_idempotency_keys_method_path_index ON public.api_idempotency_keys USING btree (method, path);


--
-- Name: batch_procedures_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX batch_procedures_status_index ON public.batch_procedures USING btree (status);


--
-- Name: batch_runs_batch_procedure_id_business_date_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX batch_runs_batch_procedure_id_business_date_status_index ON public.batch_runs USING btree (batch_procedure_id, business_date, status);


--
-- Name: batch_runs_business_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX batch_runs_business_date_index ON public.batch_runs USING btree (business_date);


--
-- Name: batch_runs_idempotency_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX batch_runs_idempotency_key_index ON public.batch_runs USING btree (idempotency_key);


--
-- Name: batch_runs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX batch_runs_status_index ON public.batch_runs USING btree (status);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: causer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX causer ON public.activity_log USING btree (causer_type, causer_id);


--
-- Name: client_guarantors_client_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_guarantors_client_id_status_index ON public.client_guarantors USING btree (client_id, status);


--
-- Name: client_guarantors_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_guarantors_status_index ON public.client_guarantors USING btree (status);


--
-- Name: client_guarantors_verification_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_guarantors_verification_status_index ON public.client_guarantors USING btree (verification_status);


--
-- Name: client_identity_documents_client_id_verification_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_identity_documents_client_id_verification_status_index ON public.client_identity_documents USING btree (client_id, verification_status);


--
-- Name: client_identity_documents_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_identity_documents_status_index ON public.client_identity_documents USING btree (status);


--
-- Name: client_identity_documents_verification_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_identity_documents_verification_status_index ON public.client_identity_documents USING btree (verification_status);


--
-- Name: client_kyc_reviews_agency_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_kyc_reviews_agency_id_created_at_index ON public.client_kyc_reviews USING btree (agency_id, created_at);


--
-- Name: client_kyc_reviews_client_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_kyc_reviews_client_id_created_at_index ON public.client_kyc_reviews USING btree (client_id, created_at);


--
-- Name: client_kyc_reviews_new_kyc_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_kyc_reviews_new_kyc_status_index ON public.client_kyc_reviews USING btree (new_kyc_status);


--
-- Name: client_notification_consents_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_notification_consents_category_index ON public.client_notification_consents USING btree (category);


--
-- Name: client_notification_consents_channel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_notification_consents_channel_index ON public.client_notification_consents USING btree (channel);


--
-- Name: client_notification_consents_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_notification_consents_status_index ON public.client_notification_consents USING btree (status);


--
-- Name: client_proxies_account_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_proxies_account_status_index ON public.client_proxies USING btree (customer_account_id, status);


--
-- Name: client_proxies_client_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_proxies_client_id_status_index ON public.client_proxies USING btree (client_id, status);


--
-- Name: client_proxies_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_proxies_status_index ON public.client_proxies USING btree (status);


--
-- Name: client_proxies_verification_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX client_proxies_verification_status_index ON public.client_proxies USING btree (verification_status);


--
-- Name: clients_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clients_agency_id_status_index ON public.clients USING btree (agency_id, status);


--
-- Name: clients_kyc_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clients_kyc_status_index ON public.clients USING btree (kyc_status);


--
-- Name: clients_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clients_status_index ON public.clients USING btree (status);


--
-- Name: collaterals_loan_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX collaterals_loan_id_status_index ON public.collaterals USING btree (loan_id, status);


--
-- Name: collaterals_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX collaterals_status_index ON public.collaterals USING btree (status);


--
-- Name: currencies_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX currencies_status_index ON public.currencies USING btree (status);


--
-- Name: customer_account_signatures_account_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_account_signatures_account_status_index ON public.customer_account_signatures USING btree (customer_account_id, status);


--
-- Name: customer_account_signatures_active_primary_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX customer_account_signatures_active_primary_unique ON public.customer_account_signatures USING btree (customer_account_id) WHERE (((status)::text = 'active'::text) AND ((signature_type)::text = 'primary_holder'::text));


--
-- Name: customer_account_signatures_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_account_signatures_agency_id_status_index ON public.customer_account_signatures USING btree (agency_id, status);


--
-- Name: customer_account_signatures_client_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_account_signatures_client_status_index ON public.customer_account_signatures USING btree (client_id, status);


--
-- Name: customer_account_signatures_proxy_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_account_signatures_proxy_status_index ON public.customer_account_signatures USING btree (client_proxy_id, status);


--
-- Name: customer_accounts_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_accounts_agency_id_status_index ON public.customer_accounts USING btree (agency_id, status);


--
-- Name: customer_accounts_client_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_accounts_client_id_status_index ON public.customer_accounts USING btree (client_id, status);


--
-- Name: customer_accounts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_accounts_status_index ON public.customer_accounts USING btree (status);


--
-- Name: dashboard_definitions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dashboard_definitions_status_index ON public.dashboard_definitions USING btree (status);


--
-- Name: delinquency_trackings_agency_id_tracking_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX delinquency_trackings_agency_id_tracking_date_index ON public.delinquency_trackings USING btree (agency_id, tracking_date);


--
-- Name: delinquency_trackings_loan_id_tracking_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX delinquency_trackings_loan_id_tracking_date_index ON public.delinquency_trackings USING btree (loan_id, tracking_date);


--
-- Name: delinquency_trackings_tracking_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX delinquency_trackings_tracking_date_index ON public.delinquency_trackings USING btree (tracking_date);


--
-- Name: denominations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX denominations_status_index ON public.denominations USING btree (status);


--
-- Name: documents_agency_disk_path_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX documents_agency_disk_path_unique ON public.documents USING btree (agency_id, disk, path);


--
-- Name: documents_category_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_category_status_index ON public.documents USING btree (category, status);


--
-- Name: documents_checksum_sha256_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_checksum_sha256_index ON public.documents USING btree (checksum_sha256);


--
-- Name: documents_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documents_owner_type_owner_id_index ON public.documents USING btree (owner_type, owner_id);


--
-- Name: emf_ledger_account_mappings_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emf_ledger_account_mappings_status_index ON public.emf_ledger_account_mappings USING btree (status);


--
-- Name: emf_regulatory_accounts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emf_regulatory_accounts_status_index ON public.emf_regulatory_accounts USING btree (status);


--
-- Name: exchange_rates_effective_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exchange_rates_effective_on_index ON public.exchange_rates USING btree (effective_on);


--
-- Name: exchange_rates_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exchange_rates_status_index ON public.exchange_rates USING btree (status);


--
-- Name: fx_authorizations_authorization_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_authorizations_authorization_type_index ON public.fx_authorizations USING btree (authorization_type);


--
-- Name: fx_authorizations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_authorizations_status_index ON public.fx_authorizations USING btree (status);


--
-- Name: fx_reconciliations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_reconciliations_status_index ON public.fx_reconciliations USING btree (status);


--
-- Name: fx_stock_movements_movement_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_stock_movements_movement_date_index ON public.fx_stock_movements USING btree (movement_date);


--
-- Name: fx_stock_movements_movement_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_stock_movements_movement_type_index ON public.fx_stock_movements USING btree (movement_type);


--
-- Name: fx_stock_movements_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_stock_movements_status_index ON public.fx_stock_movements USING btree (status);


--
-- Name: fx_transactions_direction_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_transactions_direction_index ON public.fx_transactions USING btree (direction);


--
-- Name: fx_transactions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_transactions_status_index ON public.fx_transactions USING btree (status);


--
-- Name: fx_transactions_transaction_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fx_transactions_transaction_date_index ON public.fx_transactions USING btree (transaction_date);


--
-- Name: hr_attendance_records_attendance_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_attendance_records_attendance_date_index ON public.hr_attendance_records USING btree (attendance_date);


--
-- Name: hr_attendance_records_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_attendance_records_status_index ON public.hr_attendance_records USING btree (status);


--
-- Name: hr_contracts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_contracts_status_index ON public.hr_contracts USING btree (status);


--
-- Name: hr_employees_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_employees_status_index ON public.hr_employees USING btree (status);


--
-- Name: hr_leave_requests_leave_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_leave_requests_leave_type_index ON public.hr_leave_requests USING btree (leave_type);


--
-- Name: hr_leave_requests_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_leave_requests_status_index ON public.hr_leave_requests USING btree (status);


--
-- Name: hr_payroll_formula_sets_jurisdiction_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_formula_sets_jurisdiction_index ON public.hr_payroll_formula_sets USING btree (jurisdiction);


--
-- Name: hr_payroll_formula_sets_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_formula_sets_status_index ON public.hr_payroll_formula_sets USING btree (status);


--
-- Name: hr_payroll_lines_line_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_lines_line_type_index ON public.hr_payroll_lines USING btree (line_type);


--
-- Name: hr_payroll_runs_period_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_runs_period_key_index ON public.hr_payroll_runs USING btree (period_key);


--
-- Name: hr_payroll_runs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_runs_status_index ON public.hr_payroll_runs USING btree (status);


--
-- Name: hr_payroll_slips_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_payroll_slips_status_index ON public.hr_payroll_slips USING btree (status);


--
-- Name: hr_salary_advances_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_salary_advances_status_index ON public.hr_salary_advances USING btree (status);


--
-- Name: hr_sanctions_sanction_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_sanctions_sanction_type_index ON public.hr_sanctions USING btree (sanction_type);


--
-- Name: hr_sanctions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hr_sanctions_status_index ON public.hr_sanctions USING btree (status);


--
-- Name: idx_hr_payroll_formula_rates_axis; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_hr_payroll_formula_rates_axis ON public.hr_payroll_formula_rates USING btree (hr_payroll_formula_set_id, branch, sector, payer);


--
-- Name: idx_islamic_installment_status_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_islamic_installment_status_due ON public.islamic_financing_installments USING btree (islamic_financing_id, status, due_on);


--
-- Name: idx_till_currency_balance_currency_amount; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_till_currency_balance_currency_amount ON public.till_currency_balances USING btree (currency, current_balance_minor);


--
-- Name: insurance_claim_decisions_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_claim_decisions_decision_index ON public.insurance_claim_decisions USING btree (decision);


--
-- Name: insurance_claim_decisions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_claim_decisions_status_index ON public.insurance_claim_decisions USING btree (status);


--
-- Name: insurance_claims_claim_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_claims_claim_type_index ON public.insurance_claims USING btree (claim_type);


--
-- Name: insurance_claims_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_claims_status_index ON public.insurance_claims USING btree (status);


--
-- Name: insurance_partners_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_partners_status_index ON public.insurance_partners USING btree (status);


--
-- Name: insurance_premium_assessments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_premium_assessments_status_index ON public.insurance_premium_assessments USING btree (status);


--
-- Name: insurance_premium_payments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_premium_payments_status_index ON public.insurance_premium_payments USING btree (status);


--
-- Name: insurance_premium_schedules_insurance_subscription_id_status_in; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_premium_schedules_insurance_subscription_id_status_in ON public.insurance_premium_schedules USING btree (insurance_subscription_id, status);


--
-- Name: insurance_product_rule_versions_insurance_product_id_status_ind; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_product_rule_versions_insurance_product_id_status_ind ON public.insurance_product_rule_versions USING btree (insurance_product_id, status);


--
-- Name: insurance_products_product_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_products_product_type_index ON public.insurance_products USING btree (product_type);


--
-- Name: insurance_products_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_products_status_index ON public.insurance_products USING btree (status);


--
-- Name: insurance_subscriptions_client_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_subscriptions_client_id_status_index ON public.insurance_subscriptions USING btree (client_id, status);


--
-- Name: insurance_subscriptions_loan_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_subscriptions_loan_id_status_index ON public.insurance_subscriptions USING btree (loan_id, status);


--
-- Name: insurance_subscriptions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insurance_subscriptions_status_index ON public.insurance_subscriptions USING btree (status);


--
-- Name: islamic_compliance_reviews_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_compliance_reviews_decision_index ON public.islamic_compliance_reviews USING btree (decision);


--
-- Name: islamic_compliance_reviews_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_compliance_reviews_status_index ON public.islamic_compliance_reviews USING btree (status);


--
-- Name: islamic_financed_assets_asset_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_financed_assets_asset_type_index ON public.islamic_financed_assets USING btree (asset_type);


--
-- Name: islamic_financing_installments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_financing_installments_status_index ON public.islamic_financing_installments USING btree (status);


--
-- Name: islamic_financings_contract_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_financings_contract_type_index ON public.islamic_financings USING btree (contract_type);


--
-- Name: islamic_financings_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_financings_status_index ON public.islamic_financings USING btree (status);


--
-- Name: islamic_products_contract_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_products_contract_type_index ON public.islamic_products USING btree (contract_type);


--
-- Name: islamic_products_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX islamic_products_status_index ON public.islamic_products USING btree (status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: journal_entries_agency_id_business_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_entries_agency_id_business_date_index ON public.journal_entries USING btree (agency_id, business_date);


--
-- Name: journal_entries_business_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_entries_business_date_index ON public.journal_entries USING btree (business_date);


--
-- Name: journal_entries_source_module_source_type_source_public_id_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_entries_source_module_source_type_source_public_id_inde ON public.journal_entries USING btree (source_module, source_type, source_public_id);


--
-- Name: journal_entries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_entries_status_index ON public.journal_entries USING btree (status);


--
-- Name: journal_lines_journal_entry_id_ledger_account_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_lines_journal_entry_id_ledger_account_id_index ON public.journal_lines USING btree (journal_entry_id, ledger_account_id);


--
-- Name: journal_lines_loan_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX journal_lines_loan_id_index ON public.journal_lines USING btree (loan_id);


--
-- Name: ledger_accounts_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ledger_accounts_agency_id_status_index ON public.ledger_accounts USING btree (agency_id, status);


--
-- Name: ledger_accounts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ledger_accounts_status_index ON public.ledger_accounts USING btree (status);


--
-- Name: loan_approvals_agency_id_step_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_approvals_agency_id_step_decision_index ON public.loan_approvals USING btree (agency_id, step, decision);


--
-- Name: loan_approvals_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_approvals_decision_index ON public.loan_approvals USING btree (decision);


--
-- Name: loan_approvals_step_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_approvals_step_index ON public.loan_approvals USING btree (step);


--
-- Name: loan_arrears_due_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_arrears_due_on_index ON public.loan_arrears USING btree (due_on);


--
-- Name: loan_arrears_loan_id_status_due_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_arrears_loan_id_status_due_on_index ON public.loan_arrears USING btree (loan_id, status, due_on);


--
-- Name: loan_arrears_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_arrears_status_index ON public.loan_arrears USING btree (status);


--
-- Name: loan_charge_assessments_charge_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_charge_assessments_charge_type_index ON public.loan_charge_assessments USING btree (charge_type);


--
-- Name: loan_charge_assessments_loan_id_charge_type_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_charge_assessments_loan_id_charge_type_status_index ON public.loan_charge_assessments USING btree (loan_id, charge_type, status);


--
-- Name: loan_charge_assessments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_charge_assessments_status_index ON public.loan_charge_assessments USING btree (status);


--
-- Name: loan_disbursements_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_disbursements_agency_id_status_index ON public.loan_disbursements USING btree (agency_id, status);


--
-- Name: loan_disbursements_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_disbursements_status_index ON public.loan_disbursements USING btree (status);


--
-- Name: loan_guarantee_obligations_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_guarantee_obligations_agency_id_status_index ON public.loan_guarantee_obligations USING btree (agency_id, status);


--
-- Name: loan_guarantee_obligations_client_guarantor_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_guarantee_obligations_client_guarantor_id_status_index ON public.loan_guarantee_obligations USING btree (client_guarantor_id, status);


--
-- Name: loan_guarantee_obligations_loan_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_guarantee_obligations_loan_id_status_index ON public.loan_guarantee_obligations USING btree (loan_id, status);


--
-- Name: loan_guarantee_obligations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_guarantee_obligations_status_index ON public.loan_guarantee_obligations USING btree (status);


--
-- Name: loan_products_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_products_status_index ON public.loan_products USING btree (status);


--
-- Name: loan_recovery_accounts_loan_id_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_recovery_accounts_loan_id_priority_index ON public.loan_recovery_accounts USING btree (loan_id, priority);


--
-- Name: loan_recovery_accounts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_recovery_accounts_status_index ON public.loan_recovery_accounts USING btree (status);


--
-- Name: loan_recovery_attempts_loan_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_recovery_attempts_loan_id_status_index ON public.loan_recovery_attempts USING btree (loan_id, status);


--
-- Name: loan_recovery_attempts_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_recovery_attempts_status_index ON public.loan_recovery_attempts USING btree (status);


--
-- Name: loan_repayment_allocations_loan_schedule_line_id_component_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_repayment_allocations_loan_schedule_line_id_component_inde ON public.loan_repayment_allocations USING btree (loan_schedule_line_id, component);


--
-- Name: loan_repayments_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_repayments_agency_id_status_index ON public.loan_repayments USING btree (agency_id, status);


--
-- Name: loan_repayments_loan_id_paid_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_repayments_loan_id_paid_on_index ON public.loan_repayments USING btree (loan_id, paid_on);


--
-- Name: loan_repayments_paid_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_repayments_paid_on_index ON public.loan_repayments USING btree (paid_on);


--
-- Name: loan_repayments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_repayments_status_index ON public.loan_repayments USING btree (status);


--
-- Name: loan_schedule_lines_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_schedule_lines_status_index ON public.loan_schedule_lines USING btree (status);


--
-- Name: loan_schedule_snapshots_loan_id_generated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_schedule_snapshots_loan_id_generated_at_index ON public.loan_schedule_snapshots USING btree (loan_id, generated_at);


--
-- Name: loan_schedule_snapshots_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_schedule_snapshots_status_index ON public.loan_schedule_snapshots USING btree (status);


--
-- Name: loan_status_transitions_checker_decision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_status_transitions_checker_decision_index ON public.loan_status_transitions USING btree (checker_decision);


--
-- Name: loan_status_transitions_loan_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_status_transitions_loan_id_created_at_index ON public.loan_status_transitions USING btree (loan_id, created_at);


--
-- Name: loan_transfers_transfer_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loan_transfers_transfer_date_index ON public.loan_transfers USING btree (transfer_date);


--
-- Name: loans_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loans_agency_id_status_index ON public.loans USING btree (agency_id, status);


--
-- Name: loans_client_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loans_client_id_status_index ON public.loans USING btree (client_id, status);


--
-- Name: loans_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loans_status_index ON public.loans USING btree (status);


--
-- Name: media_model_type_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_model_type_model_id_index ON public.media USING btree (model_type, model_id);


--
-- Name: media_order_column_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_order_column_index ON public.media USING btree (order_column);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: notification_deliveries_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_category_index ON public.notification_deliveries USING btree (category);


--
-- Name: notification_deliveries_channel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_channel_index ON public.notification_deliveries USING btree (channel);


--
-- Name: notification_deliveries_idempotency_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX notification_deliveries_idempotency_unique ON public.notification_deliveries USING btree (idempotency_key) WHERE (idempotency_key IS NOT NULL);


--
-- Name: notification_deliveries_next_attempt_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_next_attempt_at_index ON public.notification_deliveries USING btree (next_attempt_at);


--
-- Name: notification_deliveries_recipient_type_recipient_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_recipient_type_recipient_id_index ON public.notification_deliveries USING btree (recipient_type, recipient_id);


--
-- Name: notification_deliveries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_status_index ON public.notification_deliveries USING btree (status);


--
-- Name: notification_templates_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_category_index ON public.notification_templates USING btree (category);


--
-- Name: notification_templates_channel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_channel_index ON public.notification_templates USING btree (channel);


--
-- Name: notification_templates_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_status_index ON public.notification_templates USING btree (status);


--
-- Name: operation_account_mappings_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operation_account_mappings_status_index ON public.operation_account_mappings USING btree (status);


--
-- Name: operation_codes_module_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operation_codes_module_index ON public.operation_codes USING btree (module);


--
-- Name: operation_codes_operation_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operation_codes_operation_type_index ON public.operation_codes USING btree (operation_type);


--
-- Name: operation_codes_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operation_codes_status_index ON public.operation_codes USING btree (status);


--
-- Name: otp_challenges_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX otp_challenges_expires_at_index ON public.otp_challenges USING btree (expires_at);


--
-- Name: otp_challenges_phone_number_purpose_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX otp_challenges_phone_number_purpose_index ON public.otp_challenges USING btree (phone_number, purpose);


--
-- Name: otp_challenges_user_id_purpose_used_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX otp_challenges_user_id_purpose_used_at_index ON public.otp_challenges USING btree (user_id, purpose, used_at);


--
-- Name: otp_deliveries_channel_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX otp_deliveries_channel_status_index ON public.otp_deliveries USING btree (channel, status);


--
-- Name: otp_deliveries_next_attempt_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX otp_deliveries_next_attempt_at_index ON public.otp_deliveries USING btree (next_attempt_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: regulatory_sources_authority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX regulatory_sources_authority_index ON public.regulatory_sources USING btree (authority);


--
-- Name: report_definitions_module_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_definitions_module_index ON public.report_definitions USING btree (module);


--
-- Name: report_definitions_report_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_definitions_report_type_index ON public.report_definitions USING btree (report_type);


--
-- Name: report_definitions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_definitions_status_index ON public.report_definitions USING btree (status);


--
-- Name: report_runs_review_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_runs_review_status_index ON public.report_runs USING btree (review_status);


--
-- Name: report_runs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_runs_status_index ON public.report_runs USING btree (status);


--
-- Name: sectors_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sectors_status_index ON public.sectors USING btree (status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: sms_messages_direction_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sms_messages_direction_index ON public.sms_messages USING btree (direction);


--
-- Name: sms_messages_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sms_messages_owner_type_owner_id_index ON public.sms_messages USING btree (owner_type, owner_id);


--
-- Name: sms_messages_phone_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sms_messages_phone_number_index ON public.sms_messages USING btree (phone_number);


--
-- Name: sms_messages_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sms_messages_status_index ON public.sms_messages USING btree (status);


--
-- Name: staff_agency_assignments_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_agency_assignments_agency_id_status_index ON public.staff_agency_assignments USING btree (agency_id, status);


--
-- Name: staff_agency_assignments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_agency_assignments_status_index ON public.staff_agency_assignments USING btree (status);


--
-- Name: staff_agency_assignments_user_id_status_starts_on_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_agency_assignments_user_id_status_starts_on_index ON public.staff_agency_assignments USING btree (user_id, status, starts_on);


--
-- Name: sub_sectors_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sub_sectors_status_index ON public.sub_sectors USING btree (status);


--
-- Name: subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subject ON public.activity_log USING btree (subject_type, subject_id);


--
-- Name: teller_sessions_business_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_sessions_business_date_index ON public.teller_sessions USING btree (business_date);


--
-- Name: teller_sessions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_sessions_status_index ON public.teller_sessions USING btree (status);


--
-- Name: teller_sessions_teller_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_sessions_teller_user_id_status_index ON public.teller_sessions USING btree (teller_user_id, status);


--
-- Name: teller_sessions_till_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_sessions_till_id_status_index ON public.teller_sessions USING btree (till_id, status);


--
-- Name: teller_transactions_loan_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_loan_id_index ON public.teller_transactions USING btree (loan_id);


--
-- Name: teller_transactions_reversal_of_teller_transaction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_reversal_of_teller_transaction_id_index ON public.teller_transactions USING btree (reversal_of_teller_transaction_id);


--
-- Name: teller_transactions_review_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_review_status_index ON public.teller_transactions USING btree (review_status);


--
-- Name: teller_transactions_signature_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_signature_status_index ON public.teller_transactions USING btree (customer_account_signature_id, status);


--
-- Name: teller_transactions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_status_index ON public.teller_transactions USING btree (status);


--
-- Name: teller_transactions_transaction_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_transaction_date_index ON public.teller_transactions USING btree (transaction_date);


--
-- Name: teller_transactions_transaction_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teller_transactions_transaction_type_index ON public.teller_transactions USING btree (transaction_type);


--
-- Name: till_reconciliations_reconciliation_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX till_reconciliations_reconciliation_date_index ON public.till_reconciliations USING btree (reconciliation_date);


--
-- Name: till_reconciliations_review_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX till_reconciliations_review_status_index ON public.till_reconciliations USING btree (review_status);


--
-- Name: till_reconciliations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX till_reconciliations_status_index ON public.till_reconciliations USING btree (status);


--
-- Name: tills_daily_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tills_daily_state_index ON public.tills USING btree (daily_state);


--
-- Name: tills_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tills_status_index ON public.tills USING btree (status);


--
-- Name: uniq_identity_document_number_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_identity_document_number_hash ON public.client_identity_documents USING btree (document_type, document_number_hash) WHERE (document_number_hash IS NOT NULL);


--
-- Name: uniq_loan_arrears_per_schedule_line; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_loan_arrears_per_schedule_line ON public.loan_arrears USING btree (loan_schedule_line_id) WHERE (loan_schedule_line_id IS NOT NULL);


--
-- Name: uniq_open_teller_session_per_teller; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_open_teller_session_per_teller ON public.teller_sessions USING btree (teller_user_id) WHERE ((status)::text = 'open'::text);


--
-- Name: uniq_open_teller_session_per_till; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_open_teller_session_per_till ON public.teller_sessions USING btree (till_id) WHERE ((status)::text = 'open'::text);


--
-- Name: uniq_running_agency_batch_run; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_running_agency_batch_run ON public.batch_runs USING btree (batch_procedure_id, agency_id, business_date) WHERE ((agency_id IS NOT NULL) AND ((status)::text = 'running'::text));


--
-- Name: uniq_running_global_batch_run; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_running_global_batch_run ON public.batch_runs USING btree (batch_procedure_id, business_date) WHERE ((agency_id IS NULL) AND ((status)::text = 'running'::text));


--
-- Name: uniq_successful_agency_batch_run; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_successful_agency_batch_run ON public.batch_runs USING btree (batch_procedure_id, agency_id, business_date) WHERE ((agency_id IS NOT NULL) AND ((status)::text = 'succeeded'::text));


--
-- Name: uniq_successful_global_batch_run; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_successful_global_batch_run ON public.batch_runs USING btree (batch_procedure_id, business_date) WHERE ((agency_id IS NULL) AND ((status)::text = 'succeeded'::text));


--
-- Name: users_agency_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_agency_id_status_index ON public.users USING btree (agency_id, status);


--
-- Name: users_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_status_index ON public.users USING btree (status);


--
-- Name: journal_entries customer_account_non_overdraft_after_entry_status; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER customer_account_non_overdraft_after_entry_status AFTER UPDATE OF status ON public.journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.customer_account_non_overdraft_entry_trigger_fn();


--
-- Name: journal_lines customer_account_non_overdraft_after_line_change; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER customer_account_non_overdraft_after_line_change AFTER INSERT OR DELETE OR UPDATE ON public.journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.customer_account_non_overdraft_line_trigger_fn();


--
-- Name: journal_entries journal_entries_balance_after_insert; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER journal_entries_balance_after_insert AFTER INSERT ON public.journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.journal_entries_balance_trigger_fn();


--
-- Name: journal_entries journal_entries_balance_after_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER journal_entries_balance_after_update AFTER UPDATE ON public.journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.journal_entries_balance_trigger_fn();


--
-- Name: journal_entries journal_entries_status_transitions_before_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER journal_entries_status_transitions_before_update BEFORE UPDATE OF status ON public.journal_entries FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_entry_status_transitions();


--
-- Name: journal_lines journal_lines_balance_after_change; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER journal_lines_balance_after_change AFTER INSERT OR DELETE OR UPDATE ON public.journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.journal_lines_balance_trigger_fn();


--
-- Name: journal_lines journal_lines_immutability_before_update_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER journal_lines_immutability_before_update_delete BEFORE INSERT OR DELETE OR UPDATE ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_line_immutability();


--
-- Name: journal_lines journal_lines_single_currency_before_insert_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER journal_lines_single_currency_before_insert_update BEFORE INSERT OR UPDATE OF currency, journal_entry_id ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.enforce_single_currency_journal_lines();


--
-- Name: account_holds account_holds_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds
    ADD CONSTRAINT account_holds_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE RESTRICT;


--
-- Name: account_holds account_holds_placed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds
    ADD CONSTRAINT account_holds_placed_by_user_id_foreign FOREIGN KEY (placed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: account_holds account_holds_released_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_holds
    ADD CONSTRAINT account_holds_released_by_user_id_foreign FOREIGN KEY (released_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: account_products account_products_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products
    ADD CONSTRAINT account_products_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: account_products account_products_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_products
    ADD CONSTRAINT account_products_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: agencies agencies_manager_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agencies
    ADD CONSTRAINT agencies_manager_id_foreign FOREIGN KEY (manager_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: batch_runs batch_runs_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: batch_runs batch_runs_batch_procedure_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_batch_procedure_id_foreign FOREIGN KEY (batch_procedure_id) REFERENCES public.batch_procedures(id) ON DELETE RESTRICT;


--
-- Name: batch_runs batch_runs_operator_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batch_runs
    ADD CONSTRAINT batch_runs_operator_user_id_foreign FOREIGN KEY (operator_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_guarantors client_guarantors_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: client_guarantors client_guarantors_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE CASCADE;


--
-- Name: client_guarantors client_guarantors_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_guarantors client_guarantors_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_guarantors client_guarantors_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_guarantors client_guarantors_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: client_guarantors client_guarantors_guarantor_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_guarantor_agency_foreign FOREIGN KEY (guarantor_client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_guarantors client_guarantors_guarantor_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_guarantor_client_id_foreign FOREIGN KEY (guarantor_client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: client_guarantors client_guarantors_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_guarantors
    ADD CONSTRAINT client_guarantors_verified_by_user_id_foreign FOREIGN KEY (verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_identity_documents client_identity_documents_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: client_identity_documents client_identity_documents_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_identity_documents client_identity_documents_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: client_identity_documents client_identity_documents_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_identity_documents client_identity_documents_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_identity_documents client_identity_documents_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: client_identity_documents client_identity_documents_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_identity_documents
    ADD CONSTRAINT client_identity_documents_verified_by_user_id_foreign FOREIGN KEY (verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_kyc_reviews client_kyc_reviews_acted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews
    ADD CONSTRAINT client_kyc_reviews_acted_by_user_id_foreign FOREIGN KEY (acted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_kyc_reviews client_kyc_reviews_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews
    ADD CONSTRAINT client_kyc_reviews_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: client_kyc_reviews client_kyc_reviews_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_kyc_reviews
    ADD CONSTRAINT client_kyc_reviews_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_notification_consents client_notification_consents_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT client_notification_consents_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: client_notification_consents client_notification_consents_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT client_notification_consents_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_notification_consents client_notification_consents_last_changed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_notification_consents
    ADD CONSTRAINT client_notification_consents_last_changed_by_user_id_foreign FOREIGN KEY (last_changed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_proxies client_proxies_account_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_proxies client_proxies_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: client_proxies client_proxies_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_proxies client_proxies_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_proxies client_proxies_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: client_proxies client_proxies_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE RESTRICT;


--
-- Name: client_proxies client_proxies_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: client_proxies client_proxies_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: client_proxies client_proxies_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_proxies
    ADD CONSTRAINT client_proxies_verified_by_user_id_foreign FOREIGN KEY (verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: clients clients_collection_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_collection_agent_id_foreign FOREIGN KEY (collection_agent_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_kyc_submitted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_kyc_submitted_by_user_id_foreign FOREIGN KEY (kyc_submitted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_kyc_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_kyc_verified_by_user_id_foreign FOREIGN KEY (kyc_verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_profile_photo_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_profile_photo_document_id_foreign FOREIGN KEY (profile_photo_document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: clients clients_prospector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_prospector_id_foreign FOREIGN KEY (prospector_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clients clients_sector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_sector_id_foreign FOREIGN KEY (sector_id) REFERENCES public.sectors(id) ON DELETE SET NULL;


--
-- Name: clients clients_sub_sector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_sub_sector_id_foreign FOREIGN KEY (sub_sector_id) REFERENCES public.sub_sectors(id) ON DELETE SET NULL;


--
-- Name: clients clients_sub_sector_matches_sector; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_sub_sector_matches_sector FOREIGN KEY (sub_sector_id, sector_id) REFERENCES public.sub_sectors(id, sector_id) ON DELETE SET NULL;


--
-- Name: collateral_items collateral_items_collateral_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collateral_items
    ADD CONSTRAINT collateral_items_collateral_id_foreign FOREIGN KEY (collateral_id) REFERENCES public.collaterals(id) ON DELETE CASCADE;


--
-- Name: collaterals collaterals_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: collaterals collaterals_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: collaterals collaterals_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: collaterals collaterals_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: collaterals collaterals_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: collaterals collaterals_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: collaterals collaterals_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collaterals
    ADD CONSTRAINT collaterals_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE SET NULL;


--
-- Name: customer_account_signatures customer_account_signatures_account_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_client_proxy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_client_proxy_id_foreign FOREIGN KEY (client_proxy_id) REFERENCES public.client_proxies(id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_proxy_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_proxy_agency_foreign FOREIGN KEY (client_proxy_id, agency_id) REFERENCES public.client_proxies(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_account_signatures customer_account_signatures_revoked_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_revoked_by_user_id_foreign FOREIGN KEY (revoked_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_account_signatures customer_account_signatures_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_account_signatures
    ADD CONSTRAINT customer_account_signatures_verified_by_user_id_foreign FOREIGN KEY (verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_accounts customer_accounts_account_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_account_product_id_foreign FOREIGN KEY (account_product_id) REFERENCES public.account_products(id) ON DELETE SET NULL;


--
-- Name: customer_accounts customer_accounts_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: customer_accounts customer_accounts_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_accounts customer_accounts_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: customer_accounts customer_accounts_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: customer_accounts customer_accounts_ledger_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_ledger_agency_foreign FOREIGN KEY (ledger_account_id, agency_id) REFERENCES public.ledger_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: customer_accounts customer_accounts_manager_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_accounts
    ADD CONSTRAINT customer_accounts_manager_user_id_foreign FOREIGN KEY (manager_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: dashboard_widgets dashboard_widgets_dashboard_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_dashboard_definition_id_foreign FOREIGN KEY (dashboard_definition_id) REFERENCES public.dashboard_definitions(id) ON DELETE CASCADE;


--
-- Name: delinquency_trackings delinquency_trackings_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: delinquency_trackings delinquency_trackings_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: delinquency_trackings delinquency_trackings_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: delinquency_trackings delinquency_trackings_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delinquency_trackings
    ADD CONSTRAINT delinquency_trackings_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: documents documents_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: documents documents_uploaded_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_uploaded_by_user_id_foreign FOREIGN KEY (uploaded_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: documents documents_verified_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_verified_by_user_id_foreign FOREIGN KEY (verified_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: emf_ledger_account_mappings emf_ledger_account_mappings_emf_regulatory_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings
    ADD CONSTRAINT emf_ledger_account_mappings_emf_regulatory_account_id_foreign FOREIGN KEY (emf_regulatory_account_id) REFERENCES public.emf_regulatory_accounts(id) ON DELETE CASCADE;


--
-- Name: emf_ledger_account_mappings emf_ledger_account_mappings_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_ledger_account_mappings
    ADD CONSTRAINT emf_ledger_account_mappings_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE CASCADE;


--
-- Name: emf_regulatory_accounts emf_regulatory_accounts_parent_emf_regulatory_account_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts
    ADD CONSTRAINT emf_regulatory_accounts_parent_emf_regulatory_account_id_foreig FOREIGN KEY (parent_emf_regulatory_account_id) REFERENCES public.emf_regulatory_accounts(id) ON DELETE SET NULL;


--
-- Name: emf_regulatory_accounts emf_regulatory_accounts_regulatory_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emf_regulatory_accounts
    ADD CONSTRAINT emf_regulatory_accounts_regulatory_source_id_foreign FOREIGN KEY (regulatory_source_id) REFERENCES public.regulatory_sources(id) ON DELETE SET NULL;


--
-- Name: exchange_rates exchange_rates_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: exchange_rates exchange_rates_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fx_authorizations fx_authorizations_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_authorizations
    ADD CONSTRAINT fx_authorizations_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: fx_authorizations fx_authorizations_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_authorizations
    ADD CONSTRAINT fx_authorizations_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fx_reconciliations fx_reconciliations_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT fx_reconciliations_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: fx_reconciliations fx_reconciliations_closed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT fx_reconciliations_closed_by_user_id_foreign FOREIGN KEY (closed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fx_reconciliations fx_reconciliations_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_reconciliations
    ADD CONSTRAINT fx_reconciliations_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE CASCADE;


--
-- Name: fx_stock_movements fx_stock_movements_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: fx_stock_movements fx_stock_movements_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fx_stock_movements fx_stock_movements_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: fx_stock_movements fx_stock_movements_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fx_stock_movements fx_stock_movements_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_stock_movements
    ADD CONSTRAINT fx_stock_movements_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE SET NULL;


--
-- Name: fx_transactions fx_transactions_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: fx_transactions fx_transactions_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: fx_transactions fx_transactions_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: fx_transactions fx_transactions_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_transactions
    ADD CONSTRAINT fx_transactions_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE SET NULL;


--
-- Name: hr_attendance_records hr_attendance_records_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_attendance_records
    ADD CONSTRAINT hr_attendance_records_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_contracts hr_contracts_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: hr_contracts hr_contracts_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_contracts hr_contracts_predecessor_contract_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_contracts
    ADD CONSTRAINT hr_contracts_predecessor_contract_id_foreign FOREIGN KEY (predecessor_contract_id) REFERENCES public.hr_contracts(id) ON DELETE SET NULL;


--
-- Name: hr_employee_agency_history hr_employee_agency_history_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_agency_history
    ADD CONSTRAINT hr_employee_agency_history_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: hr_employee_agency_history hr_employee_agency_history_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_agency_history
    ADD CONSTRAINT hr_employee_agency_history_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_employee_documents hr_employee_documents_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_documents
    ADD CONSTRAINT hr_employee_documents_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: hr_employee_documents hr_employee_documents_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employee_documents
    ADD CONSTRAINT hr_employee_documents_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_employees hr_employees_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: hr_employees hr_employees_supervisor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_supervisor_id_foreign FOREIGN KEY (supervisor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_employees hr_employees_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_employees
    ADD CONSTRAINT hr_employees_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_leave_requests hr_leave_requests_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests
    ADD CONSTRAINT hr_leave_requests_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_leave_requests hr_leave_requests_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests
    ADD CONSTRAINT hr_leave_requests_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_leave_requests hr_leave_requests_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_leave_requests
    ADD CONSTRAINT hr_leave_requests_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_formula_rates hr_payroll_formula_rates_hr_payroll_formula_set_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_rates
    ADD CONSTRAINT hr_payroll_formula_rates_hr_payroll_formula_set_id_foreign FOREIGN KEY (hr_payroll_formula_set_id) REFERENCES public.hr_payroll_formula_sets(id) ON DELETE CASCADE;


--
-- Name: hr_payroll_formula_sets hr_payroll_formula_sets_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT hr_payroll_formula_sets_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_formula_sets hr_payroll_formula_sets_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT hr_payroll_formula_sets_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_formula_sets hr_payroll_formula_sets_source_regulatory_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_formula_sets
    ADD CONSTRAINT hr_payroll_formula_sets_source_regulatory_source_id_foreign FOREIGN KEY (source_regulatory_source_id) REFERENCES public.regulatory_sources(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_lines hr_payroll_lines_hr_payroll_slip_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_lines
    ADD CONSTRAINT hr_payroll_lines_hr_payroll_slip_id_foreign FOREIGN KEY (hr_payroll_slip_id) REFERENCES public.hr_payroll_slips(id) ON DELETE CASCADE;


--
-- Name: hr_payroll_runs hr_payroll_runs_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_correction_of_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_correction_of_run_id_foreign FOREIGN KEY (correction_of_run_id) REFERENCES public.hr_payroll_runs(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_hr_payroll_formula_set_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_hr_payroll_formula_set_id_foreign FOREIGN KEY (hr_payroll_formula_set_id) REFERENCES public.hr_payroll_formula_sets(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_runs hr_payroll_runs_reversal_of_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_runs
    ADD CONSTRAINT hr_payroll_runs_reversal_of_run_id_foreign FOREIGN KEY (reversal_of_run_id) REFERENCES public.hr_payroll_runs(id) ON DELETE SET NULL;


--
-- Name: hr_payroll_slips hr_payroll_slips_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE RESTRICT;


--
-- Name: hr_payroll_slips hr_payroll_slips_hr_payroll_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_hr_payroll_run_id_foreign FOREIGN KEY (hr_payroll_run_id) REFERENCES public.hr_payroll_runs(id) ON DELETE CASCADE;


--
-- Name: hr_payroll_slips hr_payroll_slips_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_payroll_slips
    ADD CONSTRAINT hr_payroll_slips_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: hr_salary_advances hr_salary_advances_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_salary_advances
    ADD CONSTRAINT hr_salary_advances_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: hr_salary_advances hr_salary_advances_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_salary_advances
    ADD CONSTRAINT hr_salary_advances_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: hr_sanctions hr_sanctions_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_sanctions
    ADD CONSTRAINT hr_sanctions_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: hr_sanctions hr_sanctions_hr_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hr_sanctions
    ADD CONSTRAINT hr_sanctions_hr_employee_id_foreign FOREIGN KEY (hr_employee_id) REFERENCES public.hr_employees(id) ON DELETE CASCADE;


--
-- Name: insurance_cancellations insurance_cancellations_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: insurance_cancellations insurance_cancellations_insurance_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_insurance_subscription_id_foreign FOREIGN KEY (insurance_subscription_id) REFERENCES public.insurance_subscriptions(id) ON DELETE RESTRICT;


--
-- Name: insurance_cancellations insurance_cancellations_refund_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_refund_customer_account_id_foreign FOREIGN KEY (refund_customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: insurance_cancellations insurance_cancellations_refund_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_refund_journal_entry_id_foreign FOREIGN KEY (refund_journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_cancellations insurance_cancellations_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_cancellations
    ADD CONSTRAINT insurance_cancellations_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_claim_decisions insurance_claim_decisions_insurance_claim_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions
    ADD CONSTRAINT insurance_claim_decisions_insurance_claim_id_foreign FOREIGN KEY (insurance_claim_id) REFERENCES public.insurance_claims(id) ON DELETE RESTRICT;


--
-- Name: insurance_claim_decisions insurance_claim_decisions_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions
    ADD CONSTRAINT insurance_claim_decisions_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_claim_decisions insurance_claim_decisions_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_decisions
    ADD CONSTRAINT insurance_claim_decisions_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: insurance_claim_documents insurance_claim_documents_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_documents
    ADD CONSTRAINT insurance_claim_documents_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: insurance_claim_documents insurance_claim_documents_insurance_claim_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_documents
    ADD CONSTRAINT insurance_claim_documents_insurance_claim_id_foreign FOREIGN KEY (insurance_claim_id) REFERENCES public.insurance_claims(id) ON DELETE CASCADE;


--
-- Name: insurance_claim_evidence_configs insurance_claim_evidence_configs_insurance_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claim_evidence_configs
    ADD CONSTRAINT insurance_claim_evidence_configs_insurance_product_id_foreign FOREIGN KEY (insurance_product_id) REFERENCES public.insurance_products(id) ON DELETE CASCADE;


--
-- Name: insurance_claims insurance_claims_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: insurance_claims insurance_claims_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: insurance_claims insurance_claims_insurance_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_insurance_subscription_id_foreign FOREIGN KEY (insurance_subscription_id) REFERENCES public.insurance_subscriptions(id) ON DELETE RESTRICT;


--
-- Name: insurance_claims insurance_claims_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_claims insurance_claims_reversal_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_claims
    ADD CONSTRAINT insurance_claims_reversal_journal_entry_id_foreign FOREIGN KEY (reversal_journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_endorsements insurance_endorsements_insurance_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements
    ADD CONSTRAINT insurance_endorsements_insurance_subscription_id_foreign FOREIGN KEY (insurance_subscription_id) REFERENCES public.insurance_subscriptions(id) ON DELETE RESTRICT;


--
-- Name: insurance_endorsements insurance_endorsements_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements
    ADD CONSTRAINT insurance_endorsements_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_endorsements insurance_endorsements_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_endorsements
    ADD CONSTRAINT insurance_endorsements_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: insurance_export_records insurance_export_records_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_export_records
    ADD CONSTRAINT insurance_export_records_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: insurance_export_records insurance_export_records_generated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_export_records
    ADD CONSTRAINT insurance_export_records_generated_by_user_id_foreign FOREIGN KEY (generated_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_partners insurance_partners_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners
    ADD CONSTRAINT insurance_partners_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: insurance_partners insurance_partners_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_partners
    ADD CONSTRAINT insurance_partners_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_assessments insurance_premium_assessments_insurance_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_insurance_subscription_id_foreign FOREIGN KEY (insurance_subscription_id) REFERENCES public.insurance_subscriptions(id) ON DELETE CASCADE;


--
-- Name: insurance_premium_assessments insurance_premium_assessments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_assessments insurance_premium_assessments_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_assessments insurance_premium_assessments_rule_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_assessments
    ADD CONSTRAINT insurance_premium_assessments_rule_version_id_foreign FOREIGN KEY (rule_version_id) REFERENCES public.insurance_product_rule_versions(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payment_splits insurance_premium_payment_splits_insurance_premium_payment_id_f; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT insurance_premium_payment_splits_insurance_premium_payment_id_f FOREIGN KEY (insurance_premium_payment_id) REFERENCES public.insurance_premium_payments(id) ON DELETE CASCADE;


--
-- Name: insurance_premium_payment_splits insurance_premium_payment_splits_insurance_product_rule_version; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT insurance_premium_payment_splits_insurance_product_rule_version FOREIGN KEY (insurance_product_rule_version_split_id) REFERENCES public.insurance_product_rule_version_splits(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payment_splits insurance_premium_payment_splits_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payment_splits
    ADD CONSTRAINT insurance_premium_payment_splits_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE RESTRICT;


--
-- Name: insurance_premium_payments insurance_premium_payments_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payments insurance_premium_payments_insurance_premium_assessment_id_fore; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_insurance_premium_assessment_id_fore FOREIGN KEY (insurance_premium_assessment_id) REFERENCES public.insurance_premium_assessments(id) ON DELETE CASCADE;


--
-- Name: insurance_premium_payments insurance_premium_payments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payments insurance_premium_payments_remittance_batch_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_remittance_batch_item_id_foreign FOREIGN KEY (remittance_batch_item_id) REFERENCES public.insurance_remittance_items(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payments insurance_premium_payments_reversal_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_reversal_journal_entry_id_foreign FOREIGN KEY (reversal_journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_payments insurance_premium_payments_teller_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_payments
    ADD CONSTRAINT insurance_premium_payments_teller_transaction_id_foreign FOREIGN KEY (teller_transaction_id) REFERENCES public.teller_transactions(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_schedules insurance_premium_schedules_insurance_premium_assessment_id_for; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_insurance_premium_assessment_id_for FOREIGN KEY (insurance_premium_assessment_id) REFERENCES public.insurance_premium_assessments(id) ON DELETE SET NULL;


--
-- Name: insurance_premium_schedules insurance_premium_schedules_insurance_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_insurance_subscription_id_foreign FOREIGN KEY (insurance_subscription_id) REFERENCES public.insurance_subscriptions(id) ON DELETE CASCADE;


--
-- Name: insurance_premium_schedules insurance_premium_schedules_rule_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_premium_schedules
    ADD CONSTRAINT insurance_premium_schedules_rule_version_id_foreign FOREIGN KEY (rule_version_id) REFERENCES public.insurance_product_rule_versions(id) ON DELETE SET NULL;


--
-- Name: insurance_product_coverages insurance_product_coverages_insurance_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_coverages
    ADD CONSTRAINT insurance_product_coverages_insurance_product_id_foreign FOREIGN KEY (insurance_product_id) REFERENCES public.insurance_products(id) ON DELETE CASCADE;


--
-- Name: insurance_product_rule_version_splits insurance_product_rule_version_splits_insurance_product_rule_ve; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_version_splits
    ADD CONSTRAINT insurance_product_rule_version_splits_insurance_product_rule_ve FOREIGN KEY (insurance_product_rule_version_id) REFERENCES public.insurance_product_rule_versions(id) ON DELETE CASCADE;


--
-- Name: insurance_product_rule_version_splits insurance_product_rule_version_splits_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_version_splits
    ADD CONSTRAINT insurance_product_rule_version_splits_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE RESTRICT;


--
-- Name: insurance_product_rule_versions insurance_product_rule_versions_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT insurance_product_rule_versions_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: insurance_product_rule_versions insurance_product_rule_versions_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT insurance_product_rule_versions_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_product_rule_versions insurance_product_rule_versions_insurance_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_product_rule_versions
    ADD CONSTRAINT insurance_product_rule_versions_insurance_product_id_foreign FOREIGN KEY (insurance_product_id) REFERENCES public.insurance_products(id) ON DELETE CASCADE;


--
-- Name: insurance_products insurance_products_insurance_partner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_products
    ADD CONSTRAINT insurance_products_insurance_partner_id_foreign FOREIGN KEY (insurance_partner_id) REFERENCES public.insurance_partners(id) ON DELETE SET NULL;


--
-- Name: insurance_remittance_batches insurance_remittance_batches_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: insurance_remittance_batches insurance_remittance_batches_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: insurance_remittance_batches insurance_remittance_batches_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: insurance_remittance_batches insurance_remittance_batches_insurance_partner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_insurance_partner_id_foreign FOREIGN KEY (insurance_partner_id) REFERENCES public.insurance_partners(id) ON DELETE RESTRICT;


--
-- Name: insurance_remittance_batches insurance_remittance_batches_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_batches
    ADD CONSTRAINT insurance_remittance_batches_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: insurance_remittance_items insurance_remittance_items_insurance_premium_payment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_insurance_premium_payment_id_foreign FOREIGN KEY (insurance_premium_payment_id) REFERENCES public.insurance_premium_payments(id) ON DELETE RESTRICT;


--
-- Name: insurance_remittance_items insurance_remittance_items_insurance_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_insurance_product_id_foreign FOREIGN KEY (insurance_product_id) REFERENCES public.insurance_products(id) ON DELETE RESTRICT;


--
-- Name: insurance_remittance_items insurance_remittance_items_insurance_remittance_batch_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_insurance_remittance_batch_id_foreig FOREIGN KEY (insurance_remittance_batch_id) REFERENCES public.insurance_remittance_batches(id) ON DELETE CASCADE;


--
-- Name: insurance_remittance_items insurance_remittance_items_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_remittance_items
    ADD CONSTRAINT insurance_remittance_items_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE RESTRICT;


--
-- Name: insurance_subscriptions insurance_subscriptions_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: insurance_subscriptions insurance_subscriptions_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: insurance_subscriptions insurance_subscriptions_insurance_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_insurance_product_id_foreign FOREIGN KEY (insurance_product_id) REFERENCES public.insurance_products(id) ON DELETE RESTRICT;


--
-- Name: insurance_subscriptions insurance_subscriptions_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE SET NULL;


--
-- Name: insurance_subscriptions insurance_subscriptions_rule_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insurance_subscriptions
    ADD CONSTRAINT insurance_subscriptions_rule_version_id_foreign FOREIGN KEY (rule_version_id) REFERENCES public.insurance_product_rule_versions(id) ON DELETE SET NULL;


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_islamic_financing_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_islamic_financing_id_foreign FOREIGN KEY (islamic_financing_id) REFERENCES public.islamic_financings(id) ON DELETE CASCADE;


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_islamic_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_islamic_product_id_foreign FOREIGN KEY (islamic_product_id) REFERENCES public.islamic_products(id) ON DELETE CASCADE;


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: islamic_compliance_reviews islamic_compliance_reviews_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_compliance_reviews
    ADD CONSTRAINT islamic_compliance_reviews_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: islamic_financed_assets islamic_financed_assets_islamic_financing_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financed_assets
    ADD CONSTRAINT islamic_financed_assets_islamic_financing_id_foreign FOREIGN KEY (islamic_financing_id) REFERENCES public.islamic_financings(id) ON DELETE CASCADE;


--
-- Name: islamic_financing_installments islamic_financing_installments_islamic_financing_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments
    ADD CONSTRAINT islamic_financing_installments_islamic_financing_id_foreign FOREIGN KEY (islamic_financing_id) REFERENCES public.islamic_financings(id) ON DELETE CASCADE;


--
-- Name: islamic_financing_installments islamic_financing_installments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financing_installments
    ADD CONSTRAINT islamic_financing_installments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: islamic_financings islamic_financings_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: islamic_financings islamic_financings_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: islamic_financings islamic_financings_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: islamic_financings islamic_financings_islamic_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_islamic_product_id_foreign FOREIGN KEY (islamic_product_id) REFERENCES public.islamic_products(id) ON DELETE RESTRICT;


--
-- Name: islamic_financings islamic_financings_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: islamic_financings islamic_financings_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_financings
    ADD CONSTRAINT islamic_financings_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE SET NULL;


--
-- Name: islamic_products islamic_products_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_products
    ADD CONSTRAINT islamic_products_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: islamic_profit_sharing_terms islamic_profit_sharing_terms_islamic_financing_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.islamic_profit_sharing_terms
    ADD CONSTRAINT islamic_profit_sharing_terms_islamic_financing_id_foreign FOREIGN KEY (islamic_financing_id) REFERENCES public.islamic_financings(id) ON DELETE CASCADE;


--
-- Name: journal_entries journal_entries_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_posted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_posted_by_user_id_foreign FOREIGN KEY (posted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_reversal_of_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_reversal_of_journal_entry_id_foreign FOREIGN KEY (reversal_of_journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_reversed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_reversed_by_user_id_foreign FOREIGN KEY (reversed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journal_entries journal_entries_submitted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_submitted_by_user_id_foreign FOREIGN KEY (submitted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: journal_lines journal_lines_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: journal_lines journal_lines_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: journal_lines journal_lines_customer_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_customer_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: journal_lines journal_lines_entry_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_entry_agency_foreign FOREIGN KEY (journal_entry_id, agency_id) REFERENCES public.journal_entries(id, agency_id) ON DELETE CASCADE;


--
-- Name: journal_lines journal_lines_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE CASCADE;


--
-- Name: journal_lines journal_lines_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE RESTRICT;


--
-- Name: journal_lines journal_lines_ledger_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_ledger_agency_foreign FOREIGN KEY (ledger_account_id, agency_id) REFERENCES public.ledger_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: journal_lines journal_lines_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: journal_lines journal_lines_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE RESTRICT;


--
-- Name: ledger_accounts ledger_accounts_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: ledger_accounts ledger_accounts_parent_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_parent_account_id_foreign FOREIGN KEY (parent_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: loan_approvals loan_approvals_acted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_acted_by_user_id_foreign FOREIGN KEY (acted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_approvals loan_approvals_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_approvals loan_approvals_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_approvals
    ADD CONSTRAINT loan_approvals_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_arrears loan_arrears_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_arrears
    ADD CONSTRAINT loan_arrears_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_arrears loan_arrears_loan_schedule_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_arrears
    ADD CONSTRAINT loan_arrears_loan_schedule_line_id_foreign FOREIGN KEY (loan_schedule_line_id) REFERENCES public.loan_schedule_lines(id) ON DELETE SET NULL;


--
-- Name: loan_charge_assessments loan_charge_assessments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: loan_charge_assessments loan_charge_assessments_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_charge_assessments loan_charge_assessments_loan_schedule_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_loan_schedule_line_id_foreign FOREIGN KEY (loan_schedule_line_id) REFERENCES public.loan_schedule_lines(id) ON DELETE SET NULL;


--
-- Name: loan_charge_assessments loan_charge_assessments_reversal_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_charge_assessments
    ADD CONSTRAINT loan_charge_assessments_reversal_journal_entry_id_foreign FOREIGN KEY (reversal_journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: loan_disbursements loan_disbursements_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_disbursements loan_disbursements_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE RESTRICT;


--
-- Name: loan_disbursements loan_disbursements_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_disbursements loan_disbursements_posted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_posted_by_user_id_foreign FOREIGN KEY (posted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_disbursements loan_disbursements_transfer_account_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_disbursements
    ADD CONSTRAINT loan_disbursements_transfer_account_agency_foreign FOREIGN KEY (transfer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_guarantor_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_guarantor_agency_foreign FOREIGN KEY (client_guarantor_id, agency_id) REFERENCES public.client_guarantors(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_guarantee_obligations loan_guarantee_obligations_released_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_guarantee_obligations
    ADD CONSTRAINT loan_guarantee_obligations_released_by_user_id_foreign FOREIGN KEY (released_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_products loan_products_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_products
    ADD CONSTRAINT loan_products_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: loan_recovery_accounts loan_recovery_accounts_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts
    ADD CONSTRAINT loan_recovery_accounts_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE RESTRICT;


--
-- Name: loan_recovery_accounts loan_recovery_accounts_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_accounts
    ADD CONSTRAINT loan_recovery_accounts_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_batch_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_batch_run_id_foreign FOREIGN KEY (batch_run_id) REFERENCES public.batch_runs(id) ON DELETE SET NULL;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_loan_recovery_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_loan_recovery_account_id_foreign FOREIGN KEY (loan_recovery_account_id) REFERENCES public.loan_recovery_accounts(id) ON DELETE SET NULL;


--
-- Name: loan_recovery_attempts loan_recovery_attempts_teller_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_recovery_attempts
    ADD CONSTRAINT loan_recovery_attempts_teller_transaction_id_foreign FOREIGN KEY (teller_transaction_id) REFERENCES public.teller_transactions(id) ON DELETE SET NULL;


--
-- Name: loan_repayment_allocations loan_repayment_allocations_loan_repayment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayment_allocations
    ADD CONSTRAINT loan_repayment_allocations_loan_repayment_id_foreign FOREIGN KEY (loan_repayment_id) REFERENCES public.loan_repayments(id) ON DELETE CASCADE;


--
-- Name: loan_repayment_allocations loan_repayment_allocations_loan_schedule_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayment_allocations
    ADD CONSTRAINT loan_repayment_allocations_loan_schedule_line_id_foreign FOREIGN KEY (loan_schedule_line_id) REFERENCES public.loan_schedule_lines(id) ON DELETE RESTRICT;


--
-- Name: loan_repayments loan_repayments_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_repayments loan_repayments_customer_account_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_customer_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_repayments loan_repayments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE RESTRICT;


--
-- Name: loan_repayments loan_repayments_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_repayments loan_repayments_posted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_posted_by_user_id_foreign FOREIGN KEY (posted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_schedule_lines loan_schedule_lines_loan_schedule_snapshot_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_lines
    ADD CONSTRAINT loan_schedule_lines_loan_schedule_snapshot_id_foreign FOREIGN KEY (loan_schedule_snapshot_id) REFERENCES public.loan_schedule_snapshots(id) ON DELETE CASCADE;


--
-- Name: loan_schedule_snapshots loan_schedule_snapshots_generated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_snapshots
    ADD CONSTRAINT loan_schedule_snapshots_generated_by_user_id_foreign FOREIGN KEY (generated_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_schedule_snapshots loan_schedule_snapshots_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_schedule_snapshots
    ADD CONSTRAINT loan_schedule_snapshots_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_status_transitions loan_status_transitions_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_status_transitions loan_status_transitions_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_status_transitions loan_status_transitions_checked_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_checked_by_user_id_foreign FOREIGN KEY (checked_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_status_transitions loan_status_transitions_document_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES public.documents(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_status_transitions loan_status_transitions_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: loan_status_transitions loan_status_transitions_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loan_status_transitions loan_status_transitions_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_status_transitions
    ADD CONSTRAINT loan_status_transitions_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_transfers loan_transfers_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loan_transfers loan_transfers_approved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_approved_by_user_id_foreign FOREIGN KEY (approved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loan_transfers loan_transfers_initial_manager_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_initial_manager_id_foreign FOREIGN KEY (initial_manager_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: loan_transfers loan_transfers_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE CASCADE;


--
-- Name: loan_transfers loan_transfers_new_manager_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loan_transfers
    ADD CONSTRAINT loan_transfers_new_manager_id_foreign FOREIGN KEY (new_manager_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: loans loans_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: loans loans_amortization_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_amortization_account_id_foreign FOREIGN KEY (amortization_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: loans loans_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: loans loans_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE RESTRICT;


--
-- Name: loans loans_credit_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_credit_agent_id_foreign FOREIGN KEY (credit_agent_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loans loans_loan_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_loan_product_id_foreign FOREIGN KEY (loan_product_id) REFERENCES public.loan_products(id) ON DELETE RESTRICT;


--
-- Name: loans loans_recovery_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_recovery_account_id_foreign FOREIGN KEY (recovery_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: loans loans_sector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_sector_id_foreign FOREIGN KEY (sector_id) REFERENCES public.sectors(id) ON DELETE SET NULL;


--
-- Name: loans loans_sub_sector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_sub_sector_id_foreign FOREIGN KEY (sub_sector_id) REFERENCES public.sub_sectors(id) ON DELETE SET NULL;


--
-- Name: loans loans_sub_sector_matches_sector; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_sub_sector_matches_sector FOREIGN KEY (sub_sector_id, sector_id) REFERENCES public.sub_sectors(id, sector_id) ON DELETE SET NULL;


--
-- Name: loans loans_transfer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_transfer_account_id_foreign FOREIGN KEY (transfer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: loans loans_unpaid_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_unpaid_account_id_foreign FOREIGN KEY (unpaid_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: notification_deliveries notification_deliveries_notification_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries
    ADD CONSTRAINT notification_deliveries_notification_template_id_foreign FOREIGN KEY (notification_template_id) REFERENCES public.notification_templates(id) ON DELETE SET NULL;


--
-- Name: operation_account_mappings operation_account_mappings_credit_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings
    ADD CONSTRAINT operation_account_mappings_credit_ledger_account_id_foreign FOREIGN KEY (credit_ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: operation_account_mappings operation_account_mappings_debit_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings
    ADD CONSTRAINT operation_account_mappings_debit_ledger_account_id_foreign FOREIGN KEY (debit_ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: operation_account_mappings operation_account_mappings_operation_code_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operation_account_mappings
    ADD CONSTRAINT operation_account_mappings_operation_code_id_foreign FOREIGN KEY (operation_code_id) REFERENCES public.operation_codes(id) ON DELETE CASCADE;


--
-- Name: otp_challenges otp_challenges_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_challenges
    ADD CONSTRAINT otp_challenges_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: otp_deliveries otp_deliveries_otp_challenge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_deliveries
    ADD CONSTRAINT otp_deliveries_otp_challenge_id_foreign FOREIGN KEY (otp_challenge_id) REFERENCES public.otp_challenges(id) ON DELETE CASCADE;


--
-- Name: regulatory_sources regulatory_sources_imported_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_sources
    ADD CONSTRAINT regulatory_sources_imported_by_user_id_foreign FOREIGN KEY (imported_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: report_definitions report_definitions_regulatory_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_regulatory_source_id_foreign FOREIGN KEY (regulatory_source_id) REFERENCES public.regulatory_sources(id) ON DELETE SET NULL;


--
-- Name: report_runs report_runs_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE SET NULL;


--
-- Name: report_runs report_runs_document_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_document_id_foreign FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: report_runs report_runs_generated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_generated_by_user_id_foreign FOREIGN KEY (generated_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: report_runs report_runs_report_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_report_definition_id_foreign FOREIGN KEY (report_definition_id) REFERENCES public.report_definitions(id) ON DELETE RESTRICT;


--
-- Name: report_runs report_runs_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: report_runs report_runs_submitted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_runs
    ADD CONSTRAINT report_runs_submitted_by_user_id_foreign FOREIGN KEY (submitted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: staff_agency_assignments staff_agency_assignments_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT staff_agency_assignments_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: staff_agency_assignments staff_agency_assignments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_agency_assignments
    ADD CONSTRAINT staff_agency_assignments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: sub_sectors sub_sectors_sector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sub_sectors
    ADD CONSTRAINT sub_sectors_sector_id_foreign FOREIGN KEY (sector_id) REFERENCES public.sectors(id) ON DELETE RESTRICT;


--
-- Name: teller_sessions teller_sessions_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: teller_sessions teller_sessions_teller_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_teller_user_id_foreign FOREIGN KEY (teller_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: teller_sessions teller_sessions_till_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_till_agency_foreign FOREIGN KEY (till_id, agency_id) REFERENCES public.tills(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_sessions teller_sessions_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_sessions
    ADD CONSTRAINT teller_sessions_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_account_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES public.customer_accounts(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_client_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES public.clients(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_customer_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES public.customer_accounts(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_customer_account_signature_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_customer_account_signature_id_foreign FOREIGN KEY (customer_account_signature_id) REFERENCES public.customer_account_signatures(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_initiator_proxy_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_initiator_proxy_id_foreign FOREIGN KEY (initiator_proxy_id) REFERENCES public.client_proxies(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_loan_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES public.loans(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.loans(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_offset_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_offset_ledger_account_id_foreign FOREIGN KEY (offset_ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_operation_code_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_operation_code_id_foreign FOREIGN KEY (operation_code_id) REFERENCES public.operation_codes(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_reversal_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_reversal_foreign FOREIGN KEY (reversal_of_teller_transaction_id) REFERENCES public.teller_transactions(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_session_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_session_agency_foreign FOREIGN KEY (teller_session_id, agency_id) REFERENCES public.teller_sessions(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_signature_account_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_signature_account_foreign FOREIGN KEY (customer_account_signature_id, customer_account_id) REFERENCES public.customer_account_signatures(id, customer_account_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_signature_agency_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_signature_agency_foreign FOREIGN KEY (customer_account_signature_id, agency_id) REFERENCES public.customer_account_signatures(id, agency_id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_signature_checked_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_signature_checked_by_user_id_foreign FOREIGN KEY (signature_checked_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: teller_transactions teller_transactions_teller_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_teller_session_id_foreign FOREIGN KEY (teller_session_id) REFERENCES public.teller_sessions(id) ON DELETE RESTRICT;


--
-- Name: teller_transactions teller_transactions_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teller_transactions
    ADD CONSTRAINT teller_transactions_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE SET NULL;


--
-- Name: till_currency_balances till_currency_balances_till_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_currency_balances
    ADD CONSTRAINT till_currency_balances_till_id_foreign FOREIGN KEY (till_id) REFERENCES public.tills(id) ON DELETE CASCADE;


--
-- Name: till_reconciliation_lines till_reconciliation_lines_denomination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliation_lines
    ADD CONSTRAINT till_reconciliation_lines_denomination_id_foreign FOREIGN KEY (denomination_id) REFERENCES public.denominations(id) ON DELETE RESTRICT;


--
-- Name: till_reconciliation_lines till_reconciliation_lines_till_reconciliation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliation_lines
    ADD CONSTRAINT till_reconciliation_lines_till_reconciliation_id_foreign FOREIGN KEY (till_reconciliation_id) REFERENCES public.till_reconciliations(id) ON DELETE CASCADE;


--
-- Name: till_reconciliations till_reconciliations_counted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_counted_by_user_id_foreign FOREIGN KEY (counted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: till_reconciliations till_reconciliations_reviewed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: till_reconciliations till_reconciliations_superseded_by_till_reconciliation_id_forei; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_superseded_by_till_reconciliation_id_forei FOREIGN KEY (superseded_by_till_reconciliation_id) REFERENCES public.till_reconciliations(id) ON DELETE SET NULL;


--
-- Name: till_reconciliations till_reconciliations_teller_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.till_reconciliations
    ADD CONSTRAINT till_reconciliations_teller_session_id_foreign FOREIGN KEY (teller_session_id) REFERENCES public.teller_sessions(id) ON DELETE RESTRICT;


--
-- Name: tills tills_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: tills tills_assigned_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_assigned_user_id_foreign FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tills tills_ledger_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tills
    ADD CONSTRAINT tills_ledger_account_id_foreign FOREIGN KEY (ledger_account_id) REFERENCES public.ledger_accounts(id) ON DELETE SET NULL;


--
-- Name: users users_agency_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES public.agencies(id) ON DELETE RESTRICT;


--
-- Name: users users_invited_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_invited_by_user_id_foreign FOREIGN KEY (invited_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict FHgUEsYPHj0hQmGTEagNNx8RxECaAxW6LGvRzHXhDbOOBcN7KJ0NtHI6EUHF6aZ

--
-- PostgreSQL database dump
--

\restrict 7PIFU7PzIDd0ssofcCgdKdj4Iyjxh4Y3iAEQ3jCdvesfADPUSYXT4YpoHgKJjen

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

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

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_04_25_195343_create_permission_tables	1
5	2026_04_25_195344_create_activity_log_table	1
6	2026_04_25_220000_create_personal_access_tokens_table	1
7	2026_04_25_230000_create_api_idempotency_keys_table	1
8	2026_04_26_000000_create_otp_challenges_table	1
9	2026_04_26_010000_create_document_and_reference_foundation_tables	1
10	2026_04_28_045226_create_agencies_table	1
11	2026_04_28_045227_create_staff_agency_assignments_table	1
12	2026_04_28_045228_create_batch_procedures_table	1
13	2026_04_28_045228_create_batch_runs_table	1
14	2026_04_28_045229_create_clients_table	1
15	2026_04_28_045230_create_client_guarantors_table	1
16	2026_04_28_045230_create_client_identity_documents_table	1
17	2026_04_28_045231_create_client_proxies_table	1
18	2026_04_28_045232_create_ledger_accounts_table	1
19	2026_04_28_045233_create_customer_accounts_table	1
20	2026_04_28_045234_create_journal_entries_table	1
21	2026_04_28_045235_create_journal_lines_table	1
22	2026_04_28_045236_create_sectors_table	1
23	2026_04_28_045237_create_sub_sectors_table	1
24	2026_04_28_045238_create_denominations_table	1
25	2026_04_28_045238_create_tills_table	1
26	2026_04_28_045239_create_teller_sessions_table	1
27	2026_04_28_045240_create_teller_transactions_table	1
28	2026_04_28_045241_create_till_reconciliations_table	1
29	2026_04_28_045242_create_till_reconciliation_lines_table	1
30	2026_04_28_045243_create_loan_products_table	1
31	2026_04_28_045243_create_loans_table	1
32	2026_04_28_045244_create_loan_status_transitions_table	1
33	2026_04_28_045245_create_collaterals_table	1
34	2026_04_28_045246_create_loan_schedule_snapshots_table	1
35	2026_04_28_045247_create_loan_schedule_lines_table	1
36	2026_04_28_045300_create_account_holds_table	1
37	2026_04_28_050139_add_foundation_integrity_constraints	1
38	2026_04_28_050711_add_multi_agency_integrity_constraints	1
39	2026_04_28_051420_add_agency_scoped_credit_constraints	1
40	2026_04_28_052156_add_tenant_scoped_documents_and_ledger_accounts	1
41	2026_04_28_052400_add_agency_id_to_users_table	1
42	2026_04_28_055912_harden_staff_agency_authority_constraints	1
43	2026_04_29_031833_create_media_table	1
44	2026_04_29_040000_make_document_disk_path_nullable	1
45	2026_04_29_050000_add_public_id_to_staff_agency_assignments	1
46	2026_04_29_060000_add_request_fingerprint_to_batch_runs_table	1
47	2026_04_29_061000_add_idempotency_scope_to_batch_runs_table	1
48	2026_04_30_062530_add_module2_crm_kyc_fields_and_reviews	1
49	2026_05_03_212351_add_public_id_to_journal_lines_table	1
50	2026_05_11_000000_finalize_stakeholder_complete_schema	1
51	2026_05_11_010000_add_structural_metadata_to_agencies_table	1
52	2026_05_11_020000_add_profile_completion_fields_to_clients_table	1
53	2026_05_11_030000_add_kyc_submitter_to_clients_table	1
54	2026_05_11_040000_finalize_client_kyc_status_vocabulary	1
55	2026_05_11_050000_encrypt_client_identity_document_sensitive_fields	1
56	2026_05_11_060000_create_loan_guarantee_obligations_table	1
57	2026_05_11_070000_add_public_id_to_emf_ledger_account_mappings	1
58	2026_05_11_080000_add_public_id_to_operation_account_mappings	1
59	2026_05_11_090000_add_sector_classification_to_clients_table	1
60	2026_05_11_100000_add_review_workflow_to_journal_entries_table	1
61	2026_05_11_110000_create_loan_disbursements_table	1
62	2026_05_11_120000_create_loan_repayments_table	1
63	2026_05_16_010000_add_account_mandate_fields_to_client_proxies_table	1
64	2026_05_16_020000_allow_cash_loan_disbursement_channel	1
65	2026_05_16_030000_add_profile_handoff_fields_to_hr_employees_table	1
66	2026_05_16_040000_add_retry_state_to_notification_and_otp_deliveries	1
67	2026_05_17_010000_add_journal_balance_database_invariants	1
68	2026_05_17_020000_add_initiator_metadata_to_teller_transactions_table	1
69	2026_05_17_030000_add_partial_unique_indexes_to_batch_runs	1
70	2026_05_17_040000_add_partial_unique_indexes_to_teller_sessions_and_loan_arrears	1
71	2026_05_17_050000_add_journal_line_immutability_and_status_transition_triggers	1
72	2026_05_17_060000_add_account_control_policy_fields_and_hold_metadata	1
73	2026_05_18_000000_create_customer_account_signatures_table	1
74	2026_05_18_010000_add_signature_check_to_teller_transactions_table	1
75	2026_05_18_020000_create_insurance_claim_decisions_table	1
76	2026_05_19_010000_extend_notification_consent_and_outbox	1
77	2026_05_19_020000_extend_fx_authorizations_and_reconciliation	1
78	2026_05_20_010000_extend_emf_regulatory_reporting	1
79	2026_05_20_020000_extend_hr_payroll_formula_and_contract_versioning	1
80	2026_05_20_030000_extend_islamic_finance_for_murabaha	1
81	2026_05_20_040000_extend_insurance_for_product_rules_and_lifecycle	1
82	2026_05_21_210000_reconcile_insurance_product_columns	1
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 82, true);


--
-- PostgreSQL database dump complete
--

\unrestrict 7PIFU7PzIDd0ssofcCgdKdj4Iyjxh4Y3iAEQ3jCdvesfADPUSYXT4YpoHgKJjen

