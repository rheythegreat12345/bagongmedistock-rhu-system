# MediStock RHU

## Missing Features and Improvement Report

**Assessment date:** November 13, 2026  
**Scope:** Local MediStock RHU Laravel project and the supplied technical documentation  
**Assessment type:** Source-code and feature-coverage review only

## Executive Summary

MediStock RHU already provides the core functions needed for a medicine inventory system: medicine and batch records, FEFO stock release, stock-in and stock-out workflows, barcode support, role-based permissions, audit logs, notifications, reports, account approval, and user management.

The remaining gaps are concentrated in security hardening, safe data export, production readiness, and operating procedures. These are recommended improvements; no website source code was modified as part of this report.

## Existing Features Confirmed

| Area | Available capability |
| --- | --- |
| Inventory | Medicine catalog, suppliers, categories, batches, stock-in, stock-out, and adjustments |
| Stock safety | FEFO batch selection, expiry checks, stock validation, database transactions, and audit records |
| Access control | User roles, permissions, administrator-only functions, account approval, and inactive-account checks on protected permission routes |
| Accountability | Audit logs, transaction history, notifications, and reporting |
| Search and scanning | Medicine search, barcode lookup, scanner registration, restocking, and dispensing workflows |
| Account functions | Login, registration OTP, password reset, profile management, and optional Google sign-in support |

## Missing or Incomplete Features

| Priority | Feature gap | Why it matters | Recommended completion |
| --- | --- | --- | --- |
| High | Hash-only password storage | The custom authentication provider and profile password validation still allow plaintext-password compatibility. | Store only Laravel-hashed passwords, remove plaintext comparison, and migrate existing records once. |
| High | Legacy password migration | Existing database entries may contain passwords that were stored before hashing was enforced. | Run a one-time, tested migration that hashes only non-hashed passwords. |
| Medium | Active-session revocation | A user who is deactivated after signing in may still access pages that use authentication without a fresh active-status check. | Add active-account middleware to the authenticated application area or revoke the user's sessions when deactivated. |
| Medium | Formula-safe CSV export | Exported text beginning with `=`, `+`, `-`, or `@` may be interpreted as a spreadsheet formula. | Prefix formula-like values with an apostrophe before writing CSV output. Apply this to medicine and report exports. |
| Medium | Scanner permission separation | Scanner dispense routes are grouped under stock-receive access even though dispensing is a stock-release action. | Apply `stock.release` permission directly to scanner-dispense routes and retain controller checks. |
| Medium | Production security configuration | HTTPS enforcement, secure cookies, production debug settings, restricted database access, and secrets handling are not verified in the repository. | Create and apply a production deployment checklist before public launch. |
| Medium | Backup and restoration process | A tested backup, restore, and recovery process is not evidenced. | Define backup retention, perform a restore test, and record the result. |
| Medium | Monitoring and alerting | No documented uptime, error, security-alert, or audit-review process was found. | Configure monitoring and assign an owner for alert review. |
| Medium | Privacy retention controls | Dispensing can store recipient information, but retention and disposal rules are not defined. | Define purpose, access restrictions, retention period, and secure disposal with the RHU/DPO. |
| Low | Google OAuth production readiness | Google sign-in exists, but production credentials and allowed identity restrictions require verification. | Configure protected credentials, restrict allowed accounts or domains, and test the callback flow. |

## Recommended Implementation Order

1. Enforce hash-only passwords and migrate legacy passwords.
2. Prevent inactive users from using existing sessions.
3. Protect CSV exports from spreadsheet formula injection.
4. Separate scanner receive and dispense permissions.
5. Complete production deployment, backup, monitoring, and privacy procedures.

## Completion Criteria

Each application-level improvement should include an automated Pest feature test. Operational improvements should include a dated, sanitized verification record such as a backup-restore test, deployment checklist, or monitoring test.

## Final Assessment

MediStock RHU is feature-rich for local RHU medicine inventory operations. Completing the listed security and operational improvements will make it better prepared for production deployment and formal capstone evaluation.
