<?php

/*
 * Canonical API routes, served under /api/v1 (see bootstrap/app.php, which
 * adds the `web` middleware group, the /api/v1 prefix and the `v1.` name
 * prefix). Controllers are shared with the deprecated routes in routes/legacy.php.
 *
 * Conventions (SPEC.md section 3): plural kebab-case nouns, HTTP verbs for
 * actions, no closures, ids constrained with whereNumber(), every route named.
 */

use Illuminate\Support\Facades\Route;

// Routes are added slice by slice: public/auth/account (T3), documents and
// applications (T4), career (T5-T6), admin (T7).
