<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Support;

/**
 * A realistic plain-format pg_dump (`-F p`) used by the PostgreSQL restore
 * tests. Layout mirrors pg_dump 16: preamble, tables and sequences, column
 * defaults, COPY data, setval, then constraints, indexes and foreign keys.
 */
trait BuildsPgDumpFixtures
{
    protected function pgDump(string $extraTable = ''): string
    {
        return <<<'SQL'
--
-- PostgreSQL database dump
--

-- Dumped from database version 16.2
-- Dumped by pg_dump version 16.2

SET statement_timeout = 0;
SET lock_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: sbr_pg_users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sbr_pg_users (
    id integer NOT NULL,
    name character varying(64) NOT NULL,
    note text
);


ALTER TABLE public.sbr_pg_users OWNER TO postgres;

--
-- Name: sbr_pg_users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sbr_pg_users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.sbr_pg_users_id_seq OWNER TO postgres;

--
-- Name: sbr_pg_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sbr_pg_users_id_seq OWNED BY public.sbr_pg_users.id;


--
-- Name: sbr_pg_posts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sbr_pg_posts (
    id integer NOT NULL,
    user_id integer NOT NULL,
    body text
);


ALTER TABLE public.sbr_pg_posts OWNER TO postgres;

--
-- Name: sbr_pg_users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sbr_pg_users ALTER COLUMN id SET DEFAULT nextval('public.sbr_pg_users_id_seq'::regclass);


--
-- Data for Name: sbr_pg_users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sbr_pg_users (id, name, note) FROM stdin;
1	alice	\N
2	bob	line one\nline two\ttabbed
3	-- Name: not_a_header; Type: TABLE; Schema: public; Owner: x	x
\.


--
-- Data for Name: sbr_pg_posts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sbr_pg_posts (id, user_id, body) FROM stdin;
10	1	hello
11	2	world
\.


--
-- Name: sbr_pg_users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sbr_pg_users_id_seq', 3, true);


--
-- Name: sbr_pg_posts sbr_pg_posts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sbr_pg_posts
    ADD CONSTRAINT sbr_pg_posts_pkey PRIMARY KEY (id);


--
-- Name: sbr_pg_users sbr_pg_users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sbr_pg_users
    ADD CONSTRAINT sbr_pg_users_pkey PRIMARY KEY (id);


--
-- Name: sbr_pg_posts_user_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sbr_pg_posts_user_idx ON public.sbr_pg_posts USING btree (user_id);


--
-- Name: sbr_pg_posts sbr_pg_posts_user_fk; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sbr_pg_posts
    ADD CONSTRAINT sbr_pg_posts_user_fk FOREIGN KEY (user_id) REFERENCES public.sbr_pg_users(id);

SQL
        . $extraTable
        . "\n--\n-- PostgreSQL database dump complete\n--\n\n";
    }
}
