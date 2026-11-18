#include "app.h"
#include <arpa/inet.h>
#include <ctype.h>
#include <sodium.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>
#ifdef __linux__
#include <crypt.h>
#endif

static void js(json_object *o, const char *k, const char *v) {
    json_object_object_add(o, k, json_object_new_string(v ? v : ""));
}
static void ji(json_object *o, const char *k, int64_t v) {
    json_object_object_add(o, k, json_object_new_int64(v));
}
static Result result(int status, json_object *j) {
    Result r = {0};
    r.status = status;
    r.json = j;
    return r;
}
Result fail(int status, const char *code, const char *message) {
    json_object *o = json_object_new_object();
    js(o, "error", code);
    js(o, "message", message);
    return result(status, o);
}
static Result ok(void) {
    json_object *o = json_object_new_object();
    json_object_object_add(o, "ok", json_object_new_boolean(1));
    return result(200, o);
}
static Result dberr(App *a) {
    fprintf(stderr, "database error: %s\n", sqlite3_errmsg(a->db));
    return fail(500, "database_error", "Database operation failed");
}
static sqlite3_stmt *stmt(App *a, const char *sql) {
    sqlite3_stmt *s = NULL;
    if (sqlite3_prepare_v2(a->db, sql, -1, &s, NULL) != SQLITE_OK)
        return NULL;
    return s;
}
static void bind_text(sqlite3_stmt *s, int n, const char *v) {
    sqlite3_bind_text(s, n, v, -1, SQLITE_TRANSIENT);
}
static int run(sqlite3_stmt *s) {
    if (!s)
        return 0;
    int r = sqlite3_step(s);
    sqlite3_finalize(s);
    return r == SQLITE_DONE;
}
static int execsql(App *a, const char *sql) {
    return sqlite3_exec(a->db, sql, NULL, NULL, NULL) == SQLITE_OK;
}
static const char *col(sqlite3_stmt *s, int n) {
    const unsigned char *p = sqlite3_column_text(s, n);
    return p ? (const char *)p : "";
}
static const char *str(json_object *o, const char *k, size_t max) {
    json_object *v = NULL;
    if (!o || !json_object_object_get_ex(o, k, &v) || !json_object_is_type(v, json_type_string))
        return NULL;
    const char *s = json_object_get_string(v);
    size_t n = (size_t)json_object_get_string_len(v);
    if (n > max || strlen(s) != n)
        return NULL;
    return s;
}
static int num(json_object *o, const char *k) {
    json_object *v = NULL;
    if (!o || !json_object_object_get_ex(o, k, &v) || !json_object_is_type(v, json_type_int))
        return -1;
    int64_t n = json_object_get_int64(v);
    return n > 0 && n <= 2147483647 ? (int)n : -1;
}
static int boolean(json_object *o, const char *k) {
    json_object *v = NULL;
    if (!o || !json_object_object_get_ex(o, k, &v) || !json_object_is_type(v, json_type_boolean))
        return -1;
    return json_object_get_boolean(v);
}
static int textvalid(const char *s, int nonempty) {
    if (!s || (nonempty && !*s))
        return 0;
    for (const unsigned char *p = (const unsigned char *)s; *p; p++)
        if (*p < 32 && *p != '\n' && *p != '\t')
            return 0;
    return 1;
}
static int textwithin(const char *s, size_t max) {
    if (!s)
        return 0;
    size_t count = 0;
    /* The HTTP JSON parser validates UTF-8; count each code point's first byte. */
    for (const unsigned char *p = (const unsigned char *)s; *p; p++)
        if ((*p & 0xc0) != 0x80 && ++count > max)
            return 0;
    return 1;
}
static int loginvalid(const char *s) {
    if (!s || !*s || !isalnum((unsigned char)*s))
        return 0;
    for (const char *p = s; *p; p++)
        if (!((*p >= 'a' && *p <= 'z') || isdigit((unsigned char)*p) || *p == '_' || *p == '-' ||
              *p == '.'))
            return 0;
    return 1;
}
static int datevalid(const char *s, int *wday) {
    if (!s || strlen(s) != 10 || s[4] != '-' || s[7] != '-')
        return 0;
    for (int i = 0; i < 10; i++)
        if (i != 4 && i != 7 && !isdigit((unsigned char)s[i]))
            return 0;
    int y, m, d;
    if (sscanf(s, "%4d-%2d-%2d", &y, &m, &d) != 3 || y < 1900 || y > 2199 || m < 1 || m > 12 ||
        d < 1 || d > 31)
        return 0;
    struct tm t = {0};
    t.tm_year = y - 1900;
    t.tm_mon = m - 1;
    t.tm_mday = d;
    t.tm_hour = 12;
    t.tm_isdst = -1;
    time_t val = mktime(&t);
    if (val == (time_t)-1 || t.tm_year != y - 1900 || t.tm_mon != m - 1 || t.tm_mday != d)
        return 0;
    if (wday)
        *wday = t.tm_wday;
    return 1;
}
static void today(char out[11]) {
    time_t now = time(NULL);
    struct tm t;
    localtime_r(&now, &t);
    strftime(out, 11, "%Y-%m-%d", &t);
}
static int adddays(const char *base, int days, char out[11]) {
    int y, m, d;
    if (!datevalid(base, NULL) || sscanf(base, "%4d-%2d-%2d", &y, &m, &d) != 3)
        return 0;
    struct tm t = {0};
    t.tm_year = y - 1900;
    t.tm_mon = m - 1;
    t.tm_mday = d + days;
    t.tm_hour = 12;
    t.tm_isdst = -1;
    return mktime(&t) != (time_t)-1 && strftime(out, 11, "%Y-%m-%d", &t) == 10 &&
           datevalid(out, NULL);
}
static void hexrandom(char out[65]) {
    unsigned char b[32];
    randombytes_buf(b, sizeof b);
    sodium_bin2hex(out, 65, b, sizeof b);
    sodium_memzero(b, sizeof b);
}
static void digest(const char *s, char out[65]) {
    unsigned char hash[32];
    crypto_generichash(hash, sizeof hash, (const unsigned char *)s, strlen(s), NULL, 0);
    sodium_bin2hex(out, 65, hash, sizeof hash);
}
static void newpassword(char out[33]) {
    unsigned char b[16];
    randombytes_buf(b, sizeof b);
    sodium_bin2hex(out, 33, b, sizeof b);
    sodium_memzero(b, sizeof b);
}
/* Only trusted offline migration can introduce bcrypt. New passwords are
 * always Argon2id. Linux libxcrypt verifies legacy hashes without PHP runtime. */
static int verify_password(const char *hash, const char *pw) {
    if (!strncmp(hash, "$argon2id$", 10))
        return crypto_pwhash_str_verify(hash, pw, strlen(pw)) == 0;
#ifdef __linux__
    if (strlen(hash) == 60 &&
        (!strncmp(hash, "$2y$", 4) || !strncmp(hash, "$2b$", 4) || !strncmp(hash, "$2a$", 4)) &&
        isdigit((unsigned char)hash[4]) && isdigit((unsigned char)hash[5]) && hash[6] == '$') {
        int cost = (hash[4] - '0') * 10 + hash[5] - '0';
        if (cost < 4 || cost > 16 || strlen(pw) > 72)
            return 0;
        struct crypt_data data = {0};
        char *calculated = crypt_r(pw, hash, &data);
        int valid =
            calculated && strlen(calculated) == 60 && sodium_memcmp(calculated, hash, 60) == 0;
        sodium_memzero(&data, sizeof data);
        return valid;
    }
#endif
    return 0;
}
static const char *normalized_login(const char *input, char out[61]) {
    if (!input)
        return NULL;
    while (*input && isspace((unsigned char)*input))
        input++;
    size_t n = strlen(input);
    while (n && isspace((unsigned char)input[n - 1]))
        n--;
    if (n < 3 || n > 60)
        return NULL;
    for (size_t i = 0; i < n; i++)
        out[i] = (char)tolower((unsigned char)input[i]);
    out[n] = 0;
    return out;
}
static int hashpassword(const char *pw, char out[crypto_pwhash_STRBYTES]) {
    return crypto_pwhash_str(out, pw, strlen(pw), crypto_pwhash_OPSLIMIT_INTERACTIVE,
                             crypto_pwhash_MEMLIMIT_INTERACTIVE) == 0;
}
int db_open(App *a, const char *path, int create) {
    int flags = SQLITE_OPEN_READWRITE | (create ? SQLITE_OPEN_CREATE : 0);
    if (sqlite3_open_v2(path, &a->db, flags, NULL) != SQLITE_OK)
        return 0;
    if (chmod(path, 0600) != 0)
        return 0;
    sqlite3_busy_timeout(a->db, 5000);
    return execsql(a, "PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; "
                      "PRAGMA trusted_schema=OFF;");
}
void db_close(App *a) {
    if (a->db)
        sqlite3_close(a->db);
    a->db = NULL;
}
static void audit(App *a, int uid, const char *action, int target) {
    sqlite3_stmt *s = stmt(a, "INSERT INTO audit(user_id,action,target_id) VALUES(?,?,?)");
    if (s) {
        sqlite3_bind_int(s, 1, uid);
        bind_text(s, 2, action);
        sqlite3_bind_int(s, 3, target);
        if (!run(s))
            fprintf(stderr, "audit write failed\n");
    }
}
static int identity(App *a, struct MHD_Connection *c, Identity *u) {
    memset(u, 0, sizeof *u);
    const char *token = MHD_lookup_connection_value(c, MHD_COOKIE_KIND, "shift_session");
    if (!token || strlen(token) != 64)
        return 0;
    for (const char *p = token; *p; p++)
        if (!isxdigit((unsigned char)*p))
            return 0;
    digest(token, u->session_hash);
    sqlite3_stmt *s =
        stmt(a, "SELECT u.id,u.login,u.name,u.role,u.must_change,s.csrf FROM sessions s JOIN users "
                "u ON u.id=s.user_id WHERE s.hash=? AND s.expires>? AND u.active=1");
    if (!s)
        return 0;
    bind_text(s, 1, u->session_hash);
    sqlite3_bind_int64(s, 2, (sqlite3_int64)time(NULL));
    if (sqlite3_step(s) == SQLITE_ROW) {
        u->id = sqlite3_column_int(s, 0);
        snprintf(u->login, sizeof u->login, "%s", col(s, 1));
        snprintf(u->name, sizeof u->name, "%s", col(s, 2));
        u->admin = !strcmp(col(s, 3), "admin");
        u->must_change = sqlite3_column_int(s, 4);
        snprintf(u->csrf, sizeof u->csrf, "%s", col(s, 5));
    }
    sqlite3_finalize(s);
    return u->id > 0;
}
static Result session(const Identity *u) {
    json_object *o = json_object_new_object();
    if (!u->id) {
        json_object_object_add(o, "user", NULL);
        return result(200, o);
    }
    json_object *j = json_object_new_object();
    ji(j, "id", u->id);
    js(j, "login", u->login);
    js(j, "name", u->name);
    js(j, "role", u->admin ? "admin" : "member");
    json_object_object_add(j, "must_change_password", json_object_new_boolean(u->must_change));
    json_object_object_add(o, "user", j);
    js(o, "csrf", u->csrf);
    return result(200, o);
}
/* Single HTTP worker owns these buckets. No sleeps and no account-wide lock.
   The opt-in proxy mode is for Tailscale Serve/Funnel, which REPLACES XFF.
   Never enable it behind a proxy that merely appends an untrusted header. */
static int hash_budget(App *a, struct MHD_Connection *c, double cost) {
    unsigned char key[17] = {0};
    const union MHD_ConnectionInfo *info =
        MHD_get_connection_info(c, MHD_CONNECTION_INFO_CLIENT_ADDRESS);
    if (!info || !info->client_addr)
        return 0;
    const struct sockaddr *addr = info->client_addr;
    int loopback = 0;
    if (addr->sa_family == AF_INET) {
        const struct sockaddr_in *v4 = (const struct sockaddr_in *)addr;
        key[0] = 4;
        memcpy(key + 1, &v4->sin_addr, 4);
        loopback = key[1] == 127;
    } else if (addr->sa_family == AF_INET6) {
        const struct sockaddr_in6 *v6 = (const struct sockaddr_in6 *)addr;
        key[0] = 6;
        memcpy(key + 1, &v6->sin6_addr, 16);
        loopback = IN6_IS_ADDR_LOOPBACK(&v6->sin6_addr);
    } else
        return 0;
    if (a->trust_tailscale_proxy && loopback) {
        const char *forwarded = MHD_lookup_connection_value(c, MHD_HEADER_KIND, "X-Forwarded-For");
        if (forwarded) {
            unsigned char raw[16] = {0};
            int family = inet_pton(AF_INET, forwarded, raw) == 1    ? 4
                         : inet_pton(AF_INET6, forwarded, raw) == 1 ? 6
                                                                    : 0;
            if (!family)
                return 0; /* Lists, ports and malformed values are not Tailscale XFF. */
            memset(key, 0, sizeof key);
            key[0] = (unsigned char)family;
            memcpy(key + 1, raw, family == 4 ? 4u : 16u);
        }
    }
    /* Canonicalize mapped IPv4; group IPv6 clients by /64. */
    if (key[0] == 6) {
        static const unsigned char mapped[12] = {0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 255};
        if (!memcmp(key + 1, mapped, sizeof mapped)) {
            memmove(key + 1, key + 13, 4);
            memset(key + 5, 0, 12);
            key[0] = 4;
        } else
            memset(key + 9, 0, 8);
    }
    struct timespec ts;
    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0)
        return 0;
    double now = (double)ts.tv_sec + (double)ts.tv_nsec / 1000000000.0;
    HashBucket *bucket = NULL, *reusable = NULL;
    for (size_t i = 0; i < sizeof a->hash_buckets / sizeof a->hash_buckets[0]; i++) {
        HashBucket *candidate = &a->hash_buckets[i];
        if (candidate->key[0] && !memcmp(candidate->key, key, sizeof key)) {
            bucket = candidate;
            break;
        }
        if (!reusable && (!candidate->key[0] || now - candidate->updated >= 100.0))
            reusable = candidate;
    }
    if (!bucket) {
        if (!(bucket = reusable))
            return 0; /* Never evict an active client's limit. */
        memcpy(bucket->key, key, sizeof key);
        bucket->tokens = 20.0;
        bucket->updated = now;
    }
    bucket->tokens += (now - bucket->updated) * 0.2;
    if (bucket->tokens > 20.0)
        bucket->tokens = 20.0;
    bucket->updated = now;
    if (bucket->tokens < cost)
        return 0;
    bucket->tokens -= cost;
    return 1;
}
static Result login(App *a, struct MHD_Connection *c, json_object *j) {
    char login_buffer[61];
    const char *name = normalized_login(str(j, "login", 120), login_buffer),
               *pw = str(j, "password", 128);
    if (!name || !pw || !*pw)
        return fail(400, "invalid_login", "Login and password are required");
    if (!hash_budget(a, c, 1.0))
        return fail(429, "busy", "Too many attempts; retry shortly");
    sqlite3_stmt *s = stmt(a, "SELECT id,password,active FROM users WHERE login=?");
    if (!s)
        return dberr(a);
    bind_text(s, 1, name);
    char legacy_hash[61] = {0};
    int uid = 0, valid = 0, password_valid = 0, rc = sqlite3_step(s);
    if (rc == SQLITE_ROW) {
        uid = sqlite3_column_int(s, 0);
        password_valid = verify_password(col(s, 1), pw);
        valid = password_valid && sqlite3_column_int(s, 2);
        if (valid && !strncmp(col(s, 1), "$2", 2))
            snprintf(legacy_hash, sizeof legacy_hash, "%s", col(s, 1));
    } else if (rc == SQLITE_DONE) {
        /* Unknown names consume the same KDF budget but cannot evict counters. */
        char dummy[crypto_pwhash_STRBYTES];
        if (!hashpassword(pw, dummy)) {
            sqlite3_finalize(s);
            return fail(503, "unavailable", "Try again later");
        }
        sodium_memzero(dummy, sizeof dummy);
    } else {
        sqlite3_finalize(s);
        return dberr(a);
    }
    sqlite3_finalize(s);
    if (!valid)
        return fail(401, "invalid_credentials", "Invalid login or password");
    if (*legacy_hash) {
        char upgraded[crypto_pwhash_STRBYTES];
        if (!hashpassword(pw, upgraded))
            return fail(503, "busy", "נסו שוב.");
        s = stmt(a, "UPDATE users SET password=? WHERE id=? AND password=?");
        if (!s)
            return dberr(a);
        bind_text(s, 1, upgraded);
        sqlite3_bind_int(s, 2, uid);
        bind_text(s, 3, legacy_hash);
        int good = run(s);
        sodium_memzero(upgraded, sizeof upgraded);
        if (!good)
            return dberr(a);
    }
    char token[65], hash[65], csrf[65];
    hexrandom(token);
    digest(token, hash);
    hexrandom(csrf);
    s = stmt(a, "DELETE FROM sessions WHERE expires<=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int64(s, 1, (sqlite3_int64)time(NULL));
    if (!run(s))
        return dberr(a);
    s = stmt(a, "INSERT INTO sessions(hash,user_id,csrf,expires) VALUES(?,?,?,?)");
    if (!s)
        return dberr(a);
    bind_text(s, 1, hash);
    sqlite3_bind_int(s, 2, uid);
    bind_text(s, 3, csrf);
    sqlite3_bind_int64(s, 4, (sqlite3_int64)time(NULL) + 2592000);
    if (!run(s))
        return dberr(a);
    Identity u = {0};
    u.id = uid;
    s = stmt(a, "SELECT login,name,role,must_change FROM users WHERE id=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, uid);
    if (sqlite3_step(s) == SQLITE_ROW) {
        snprintf(u.login, sizeof u.login, "%s", col(s, 0));
        snprintf(u.name, sizeof u.name, "%s", col(s, 1));
        u.admin = !strcmp(col(s, 2), "admin");
        u.must_change = sqlite3_column_int(s, 3);
    }
    sqlite3_finalize(s);
    snprintf(u.csrf, sizeof u.csrf, "%s", csrf);
    Result r = session(&u);
    snprintf(r.cookie, sizeof r.cookie,
             "shift_session=%s; Path=/; HttpOnly; SameSite=Strict; Max-Age=2592000%s", token,
             a->secure ? "; Secure" : "");
    sodium_memzero(token, sizeof token);
    audit(a, uid, "login", uid);
    return r;
}
static Result password(App *a, struct MHD_Connection *c, const Identity *u, json_object *j) {
    const char *old = str(j, "old_password", 128), *pw = str(j, "new_password", 128);
    if (!old || !pw || textwithin(pw, 11) || !strcmp(old, pw))
        return fail(400, "invalid_password",
                    "Use a new password of at least 12 characters, up to 128 UTF-8 bytes");
    if (!hash_budget(a, c, 2.0))
        return fail(429, "busy", "Too many attempts; retry shortly");
    sqlite3_stmt *s = stmt(a, "SELECT password FROM users WHERE id=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, u->id);
    if (sqlite3_step(s) != SQLITE_ROW) {
        sqlite3_finalize(s);
        return dberr(a);
    }
    int verified = verify_password(col(s, 0), old);
    sqlite3_finalize(s);
    if (!verified)
        return fail(403, "wrong_password", "Current password is incorrect");
    char hash[crypto_pwhash_STRBYTES], token[65], session_hash[65], csrf[65];
    if (!hashpassword(pw, hash))
        return fail(503, "unavailable", "Try again later");
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    /* Generate session secrets only after the KDF and BEGIN have succeeded. */
    hexrandom(token);
    digest(token, session_hash);
    hexrandom(csrf);
    s = stmt(a, "UPDATE users SET password=?,must_change=0 WHERE id=?");
    if (!s)
        goto bad;
    bind_text(s, 1, hash);
    sqlite3_bind_int(s, 2, u->id);
    if (!run(s))
        goto bad;
    s = stmt(a, "DELETE FROM sessions WHERE user_id=? AND hash!=?");
    if (!s)
        goto bad;
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, u->session_hash);
    if (!run(s))
        goto bad;
    s = stmt(a, "UPDATE sessions SET hash=?,csrf=?,expires=? WHERE hash=?");
    if (!s)
        goto bad;
    bind_text(s, 1, session_hash);
    bind_text(s, 2, csrf);
    sqlite3_bind_int64(s, 3, (sqlite3_int64)time(NULL) + 2592000);
    bind_text(s, 4, u->session_hash);
    if (!run(s))
        goto bad;
    if (!execsql(a, "COMMIT"))
        goto bad;
    audit(a, u->id, "password_changed", u->id);
    Result r = ok();
    js(r.json, "csrf", csrf);
    snprintf(r.cookie, sizeof r.cookie,
             "shift_session=%s; Path=/; HttpOnly; SameSite=Strict; Max-Age=2592000%s", token,
             a->secure ? "; Secure" : "");
    sodium_memzero(token, sizeof token);
    return r;
bad:
    sodium_memzero(token, sizeof token);
    execsql(a, "ROLLBACK");
    return dberr(a);
}

static json_object *rows(sqlite3_stmt *s) {
    if (!s)
        return NULL;
    json_object *array = json_object_new_array();
    int rc;
    while ((rc = sqlite3_step(s)) == SQLITE_ROW) {
        json_object *o = json_object_new_object();
        for (int i = 0; i < sqlite3_column_count(s); i++) {
            const char *key = sqlite3_column_name(s, i);
            json_object *v = NULL;
            int t = sqlite3_column_type(s, i);
            if (t == SQLITE_INTEGER) {
                if (!strcmp(key, "active") || !strcmp(key, "must_change_password") ||
                    !strcmp(key, "blocked"))
                    v = json_object_new_boolean(sqlite3_column_int(s, i));
                else
                    v = json_object_new_int64(sqlite3_column_int64(s, i));
            } else if (t != SQLITE_NULL)
                v = json_object_new_string(col(s, i));
            json_object_object_add(o, key, v);
        }
        json_object_array_add(array, o);
    }
    sqlite3_finalize(s);
    if (rc != SQLITE_DONE) {
        json_object_put(array);
        return NULL;
    }
    return array;
}

#include "schema.h"

/* Called after database validation and while holding the server's file lock. */
int upgrade_schema(App *a) {
    sqlite3_stmt *s = stmt(a, "PRAGMA user_version");
    if (!s)
        return 0;
    int version = sqlite3_step(s) == SQLITE_ROW ? sqlite3_column_int(s, 0) : -1;
    sqlite3_finalize(s);
    if (version == 101)
        return 1;
    if (version != 100)
        return 0;
    if (!execsql(a, signup_identity_migration_sql)) {
        execsql(a, "ROLLBACK");
        return 0;
    }
    return 1;
}

/* All scheduling choices and their exact local times live in one definition. */
typedef struct {
    const char *code, *start, *end;
} Period;
static const Period periods[] = {{"09_13", "09:00", "13:00"},
                                 {"10_14", "10:00", "14:00"},
                                 {"11_15", "11:00", "15:00"},
                                 {"12_16", "12:00", "16:00"},
                                 {"full", "09:00", "16:30"}};
static int period_index(const char *code) {
    for (int i = 0; i < 5; i++)
        if (code && !strcmp(code, periods[i].code))
            return i;
    return -1;
}
static int editable_date(const char *date) {
    char now[11], last[11];
    int weekday;
    today(now);
    return datevalid(date, &weekday) && weekday <= 4 && adddays(now, 28, last) &&
           strcmp(date, now) >= 0 && strcmp(date, last) <= 0;
}
static int closed(App *a, const char *date) {
    sqlite3_stmt *s = stmt(a, "SELECT 1 FROM blocked_dates WHERE date=?");
    if (!s)
        return -1;
    bind_text(s, 1, date);
    int rc = sqlite3_step(s);
    sqlite3_finalize(s);
    return rc == SQLITE_ROW ? 1 : rc == SQLITE_DONE ? 0 : -1;
}
static Result rollback(App *a) {
    execsql(a, "ROLLBACK");
    return dberr(a);
}
static Result array_result(const char *key, sqlite3_stmt *s, App *a) {
    json_object *array = rows(s);
    if (!array)
        return dberr(a);
    json_object *o = json_object_new_object();
    json_object_object_add(o, key, array);
    return result(200, o);
}
static int query_id(struct MHD_Connection *c) {
    const char *v = MHD_lookup_connection_value(c, MHD_GET_ARGUMENT_KIND, "id");
    if (!v || !*v)
        return -1;
    char *end;
    long n = strtol(v, &end, 10);
    return !*end && n > 0 && n <= 2147483647 ? (int)n : -1;
}
static int password_valid(const char *pw) {
    return pw && *pw && !textwithin(pw, 11) && strlen(pw) <= 128;
}
int bootstrap(App *a) {
    if (!execsql(a, schema_sql))
        return 0;
    char pw[33], hash[crypto_pwhash_STRBYTES];
    newpassword(pw);
    if (!hashpassword(pw, hash)) {
        execsql(a, "ROLLBACK");
        return 0;
    }
    sqlite3_stmt *s =
        stmt(a, "INSERT INTO users(login,name,first_name,last_name,role,password,must_change) "
                "VALUES('oleg','אולג','אולג','','admin',?,1)");
    if (!s) {
        execsql(a, "ROLLBACK");
        return 0;
    }
    bind_text(s, 1, hash);
    if (!run(s)) {
        execsql(a, "ROLLBACK");
        return 0;
    }
    for (int i = 1; i <= 9; i++) {
        char name[64];
        snprintf(name, sizeof name, "עמדה %d", i);
        s = stmt(a, "INSERT INTO desks(name) VALUES(?)");
        if (!s) {
            execsql(a, "ROLLBACK");
            return 0;
        }
        bind_text(s, 1, name);
        if (!run(s)) {
            execsql(a, "ROLLBACK");
            return 0;
        }
    }
    if (!execsql(a, "COMMIT")) {
        execsql(a, "ROLLBACK");
        return 0;
    }
    json_object *o = json_object_new_object();
    js(o, "login", "oleg");
    js(o, "password", pw);
    puts(json_object_to_json_string_ext(o, JSON_C_TO_STRING_PRETTY));
    json_object_put(o);
    sodium_memzero(pw, sizeof pw);
    sodium_memzero(hash, sizeof hash);
    return 1;
}

/* BEGIN IMMEDIATE serializes closure and replacement across HTTP requests and
 * other SQLite connections. Canceled rows remain a record of the old choice. */
static Result shift_change(App *a, const Identity *u, json_object *j, int cancel) {
    const char *date = str(j, "date", 10), *period = str(j, "period", 10);
    if (u->admin)
        return fail(403, "worker_only", "רק משתתפים יכולים להירשם למשמרת.");
    if (!editable_date(date) || (!cancel && period_index(period) < 0))
        return fail(400, "invalid_shift",
                    "בחרו יום ראשון עד חמישי, מהיום ועד 28 ימים קדימה, ומשמרת תקינה.");
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    int is_closed = closed(a, date);
    if (is_closed < 0)
        return rollback(a);
    if (!editable_date(date) || is_closed) {
        execsql(a, "ROLLBACK");
        return fail(409, "date_unavailable", "התאריך חסום או אינו זמין לשינוי.");
    }
    sqlite3_stmt *s = stmt(
        a, "SELECT period FROM shifts WHERE worker_id=? AND shift_date=? AND cancelled_at IS NULL");
    if (!s)
        return rollback(a);
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, date);
    int rc = sqlite3_step(s);
    int unchanged = !cancel && rc == SQLITE_ROW && !strcmp(col(s, 0), period);
    sqlite3_finalize(s);
    if (rc != SQLITE_ROW && rc != SQLITE_DONE)
        return rollback(a);
    if (unchanged) {
        execsql(a, "ROLLBACK");
        return ok();
    }
    s = stmt(
        a, "UPDATE shifts SET cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason=? "
           "WHERE worker_id=? AND shift_date=? AND cancelled_at IS NULL");
    if (!s)
        return rollback(a);
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, cancel ? "worker_cancelled" : "replaced");
    sqlite3_bind_int(s, 3, u->id);
    bind_text(s, 4, date);
    if (!run(s))
        return rollback(a);
    if (!cancel) {
        s = stmt(a, "INSERT INTO shifts(worker_id,shift_date,period) VALUES(?,?,?)");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, u->id);
        bind_text(s, 2, date);
        bind_text(s, 3, period);
        if (!run(s))
            return rollback(a);
    }
    if (!execsql(a, "COMMIT"))
        return rollback(a);
    audit(a, u->id, cancel ? "shift_cancelled" : "shift_selected", u->id);
    return ok();
}

static Result week_view(App *a, const Identity *u, struct MHD_Connection *c) {
    const char *start = MHD_lookup_connection_value(c, MHD_GET_ARGUMENT_KIND, "start");
    char end[11], now[11];
    int weekday;
    if (!datevalid(start, &weekday) || weekday != 0 || !adddays(start, 4, end))
        return fail(400, "invalid_week", "בחרו שבוע שמתחיל ביום ראשון.");
    json_object *o = json_object_new_object(), *choices = json_object_new_array();
    today(now);
    js(o, "today", now);
    js(o, "start", start);
    js(o, "end", end);
    for (int i = 0; i < 5; i++) {
        json_object *p = json_object_new_object();
        js(p, "code", periods[i].code);
        js(p, "start", periods[i].start);
        js(p, "end", periods[i].end);
        json_object_array_add(choices, p);
    }
    json_object_object_add(o, "periods", choices);
    sqlite3_stmt *s = stmt(
        a,
        "SELECT s.id,s.worker_id,u.name,s.shift_date AS date,s.period FROM shifts s JOIN users u "
        "ON u.id=s.worker_id WHERE s.cancelled_at IS NULL AND u.role='member' AND s.shift_date "
        "BETWEEN ? AND ? AND (?=1 OR s.worker_id=?) ORDER BY s.shift_date,u.name,s.worker_id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, start);
    bind_text(s, 2, end);
    sqlite3_bind_int(s, 3, u->admin);
    sqlite3_bind_int(s, 4, u->id);
    json_object *array = rows(s);
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "shifts", array);
    s = stmt(a, "SELECT date,reason FROM blocked_dates WHERE date BETWEEN ? AND ? ORDER BY date");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, start);
    bind_text(s, 2, end);
    array = rows(s);
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "closures", array);
    s = stmt(a, "SELECT id,date,title,kind,description FROM events WHERE date BETWEEN ? AND ? "
                "ORDER BY date,id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, start);
    bind_text(s, 2, end);
    array = rows(s);
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "events", array);
    return result(200, o);
}

static Result close_date(App *a, const Identity *u, json_object *j, int reopen) {
    const char *date = str(j, "date", 10), *reason = str(j, "reason", 600);
    char now[11];
    today(now);
    if (!datevalid(date, NULL) || strcmp(date, now) < 0 ||
        (!reopen && boolean(j, "confirm") != 1) ||
        (reason && (!textvalid(reason, 0) || !textwithin(reason, 150))))
        return fail(400, "invalid_closure", "בחרו תאריך מהיום והלאה ואשרו את ביטול ההרשמות.");
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    today(now);
    if (strcmp(date, now) < 0) {
        execsql(a, "ROLLBACK");
        return fail(409, "past_date", "התאריך כבר עבר.");
    }
    sqlite3_stmt *s;
    if (reopen) {
        s = stmt(a, "DELETE FROM blocked_dates WHERE date=?");
        if (!s)
            return rollback(a);
        bind_text(s, 1, date);
        if (!run(s))
            return rollback(a);
    } else {
        s = stmt(a, "INSERT INTO blocked_dates(date,reason,blocked_by) VALUES(?,?,?) ON "
                    "CONFLICT(date) DO NOTHING");
        if (!s)
            return rollback(a);
        bind_text(s, 1, date);
        bind_text(s, 2, reason ? reason : "");
        sqlite3_bind_int(s, 3, u->id);
        if (!run(s))
            return rollback(a);
        const char *queries[] = {
            "UPDATE shifts SET "
            "cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason='date_blocked',"
            "cancellation_note=? WHERE shift_date=? AND cancelled_at IS NULL",
            "UPDATE desk_reservations SET "
            "cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason='date_blocked',"
            "cancellation_note=? WHERE reservation_date=? AND cancelled_at IS NULL"};
        for (size_t i = 0; i < 2; i++) {
            s = stmt(a, queries[i]);
            if (!s)
                return rollback(a);
            sqlite3_bind_int(s, 1, u->id);
            bind_text(s, 2, reason ? reason : "");
            bind_text(s, 3, date);
            if (!run(s))
                return rollback(a);
        }
    }
    if (!execsql(a, "COMMIT"))
        return rollback(a);
    audit(a, u->id, reopen ? "date_reopened" : "date_closed", 0);
    return ok();
}

/* Account input is literal text. A string resembling any hash is still hashed
 * once by libsodium; imported hashes use a separate, offline migration path. */
static Result create_user(App *a, json_object *j) {
    char login_buffer[61];
    const char *login_name = normalized_login(str(j, "login", 120), login_buffer),
               *first = str(j, "first_name", 240), *last = str(j, "last_name", 240),
               *role = str(j, "role", 8), *pw = str(j, "password", 128),
               *gender = str(j, "gender", 8), *birth = str(j, "birth_date", 10);
    char now[11];
    today(now);
    if (!loginvalid(login_name) || strlen(login_name) < 3 || !textvalid(first, 1) ||
        !textwithin(first, 60) || !textvalid(last, 1) || !textwithin(last, 60) || !role ||
        (strcmp(role, "admin") && strcmp(role, "member")) || !password_valid(pw) || !gender ||
        (strcmp(gender, "male") && strcmp(gender, "female") && strcmp(gender, "other")) ||
        (birth && *birth && (!datevalid(birth, NULL) || strcmp(birth, now) > 0)))
        return fail(400, "invalid_user", "בדקו את פרטי החשבון. הסיסמה חייבת להכיל לפחות 12 תווים.");
    char hash[crypto_pwhash_STRBYTES], name[482];
    if (!hashpassword(pw, hash))
        return fail(503, "busy", "נסו שוב בעוד רגע.");
    snprintf(name, sizeof name, "%s %s", first, last);
    sqlite3_stmt *s = stmt(
        a, "INSERT INTO users(login,name,first_name,last_name,gender,birth_date,role,password) "
           "VALUES(?,?,?,?,?,?,?,?)");
    if (!s)
        return dberr(a);
    bind_text(s, 1, login_name);
    bind_text(s, 2, name);
    bind_text(s, 3, first);
    bind_text(s, 4, last);
    bind_text(s, 5, gender);
    if (birth && *birth)
        bind_text(s, 6, birth);
    else
        sqlite3_bind_null(s, 6);
    bind_text(s, 7, role);
    bind_text(s, 8, hash);
    if (!run(s)) {
        sodium_memzero(hash, sizeof hash);
        return sqlite3_errcode(a->db) == SQLITE_CONSTRAINT
                   ? fail(409, "username_taken", "שם המשתמש כבר קיים.")
                   : dberr(a);
    }
    sodium_memzero(hash, sizeof hash);
    Result r = ok();
    ji(r.json, "id", sqlite3_last_insert_rowid(a->db));
    return r;
}
static Result profile_get(App *a, const Identity *u) {
    sqlite3_stmt *s =
        stmt(a, "SELECT id,login,name,first_name,last_name,gender,birth_date,role,active, "
                "EXISTS(SELECT 1 FROM avatars WHERE user_id=users.id) AS has_avatar FROM "
                "users WHERE id=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, u->id);
    return array_result("users", s, a);
}

static int time_minutes(const char *value) {
    if (!value || strlen(value) != 5 || value[2] != ':' || !isdigit((unsigned char)value[0]) ||
        !isdigit((unsigned char)value[1]) || !isdigit((unsigned char)value[3]) ||
        !isdigit((unsigned char)value[4]))
        return -1;
    int h = (value[0] - '0') * 10 + value[1] - '0', m = (value[3] - '0') * 10 + value[4] - '0';
    return h < 24 && m < 60 ? h * 60 + m : -1;
}
static int future_start(const char *date, const char *start) {
    char current[17], requested[17];
    time_t now = time(NULL);
    struct tm t;
    localtime_r(&now, &t);
    strftime(current, sizeof current, "%Y-%m-%d %H:%M", &t);
    snprintf(requested, sizeof requested, "%s %s", date, start);
    return strcmp(requested, current) > 0;
}
static Result desk_view(App *a, const Identity *u, struct MHD_Connection *c) {
    const char *date = MHD_lookup_connection_value(c, MHD_GET_ARGUMENT_KIND, "date");
    if (!datevalid(date, NULL))
        return fail(400, "invalid_date", "בחרו תאריך תקין.");
    json_object *o = json_object_new_object();
    js(o, "date", date);
    int blocked = closed(a, date);
    if (blocked < 0) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "blocked", json_object_new_boolean(blocked));
    json_object_object_add(o, "editable", json_object_new_boolean(editable_date(date) && !blocked));
    json_object *array = rows(stmt(a, "SELECT id,name,active FROM desks ORDER BY id"));
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "desks", array);
    sqlite3_stmt *s = stmt(
        a, "SELECT id,desk_id,start_time,end_time,(worker_id=?) AS mine FROM desk_reservations "
           "WHERE reservation_date=? AND cancelled_at IS NULL ORDER BY start_time,id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, date);
    array = rows(s);
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "reservations", array);
    s = stmt(
        a,
        "SELECT r.id,r.desk_id,d.name,r.start_time,r.end_time,r.cancelled_at,r.cancellation_reason "
        "FROM desk_reservations r JOIN desks d ON d.id=r.desk_id WHERE r.reservation_date=? AND "
        "r.worker_id=? ORDER BY r.id DESC");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, date);
    sqlite3_bind_int(s, 2, u->id);
    array = rows(s);
    if (!array) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "own", array);
    return result(200, o);
}
static Result desk_reserve(App *a, const Identity *u, json_object *j, int edit, int cancel) {
    if (u->admin)
        return fail(403, "worker_only", "רק משתתפים יכולים להזמין עמדה.");
    const char *date = str(j, "date", 10), *start = str(j, "start_time", 5),
               *end = str(j, "end_time", 5);
    int id = num(j, "id"), desk = num(j, "desk_id");
    char old_date[11] = {0};
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    sqlite3_stmt *s;
    if (edit || cancel) {
        s = stmt(a, "SELECT worker_id,reservation_date,start_time,cancelled_at FROM "
                    "desk_reservations WHERE id=?");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, id);
        int rc = sqlite3_step(s);
        if (rc != SQLITE_ROW) {
            sqlite3_finalize(s);
            execsql(a, "ROLLBACK");
            return fail(404, "not_found", "ההזמנה לא נמצאה.");
        }
        int owned = sqlite3_column_int(s, 0) == u->id;
        snprintf(old_date, sizeof old_date, "%s", col(s, 1));
        int already_cancelled = sqlite3_column_type(s, 3) != SQLITE_NULL;
        int upcoming = future_start(old_date, col(s, 2));
        sqlite3_finalize(s);
        if (!owned) {
            execsql(a, "ROLLBACK");
            return fail(403, "forbidden", "אפשר לשנות רק את ההזמנה שלך.");
        }
        if (cancel && already_cancelled) {
            execsql(a, "ROLLBACK");
            return ok();
        }
        if (already_cancelled || !upcoming) {
            execsql(a, "ROLLBACK");
            return fail(409, "reservation_started", "ניתן לשנות הזמנה רק לפני שעת ההתחלה.");
        }
        date = old_date;
    }
    int blocked = closed(a, date ? date : "");
    if (blocked < 0)
        return rollback(a);
    if (!editable_date(date) || blocked) {
        execsql(a, "ROLLBACK");
        return fail(409, "date_unavailable", "התאריך אינו זמין להזמנה.");
    }
    if (!cancel) {
        int from = time_minutes(start), to = time_minutes(end);
        if (from < 540 || to > 990 || to - from < 60 || from % 30 || to % 30 ||
            !future_start(date, start) || desk < 1) {
            execsql(a, "ROLLBACK");
            return fail(400, "invalid_interval",
                        "בחרו שעה עתידית בין 09:00 ל־16:30, לפחות שעה ובמרווחי חצי שעה.");
        }
        s = stmt(a, "SELECT id FROM desks WHERE id=? AND active=1");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, desk);
        int found = sqlite3_step(s) == SQLITE_ROW;
        sqlite3_finalize(s);
        if (!found) {
            execsql(a, "ROLLBACK");
            return fail(404, "desk_unavailable", "העמדה אינה זמינה.");
        }
        s = stmt(
            a,
            "SELECT id FROM desk_reservations WHERE cancelled_at IS NULL AND reservation_date=? "
            "AND id!=? AND (worker_id=? OR (desk_id=? AND start_time<? AND end_time>?)) LIMIT 1");
        if (!s)
            return rollback(a);
        bind_text(s, 1, date);
        sqlite3_bind_int(s, 2, edit ? id : 0);
        sqlite3_bind_int(s, 3, u->id);
        sqlite3_bind_int(s, 4, desk);
        bind_text(s, 5, end);
        bind_text(s, 6, start);
        int rc = sqlite3_step(s);
        sqlite3_finalize(s);
        if (rc == SQLITE_ROW) {
            execsql(a, "ROLLBACK");
            return fail(409, "desk_conflict", "העמדה תפוסה או שכבר יש לך הזמנה ביום זה.");
        }
        if (rc != SQLITE_DONE)
            return rollback(a);
    }
    if (edit || cancel) {
        s = stmt(a,
                 "UPDATE desk_reservations SET "
                 "cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason=? WHERE id=?");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, u->id);
        bind_text(s, 2, cancel ? "worker_cancelled" : "replaced");
        sqlite3_bind_int(s, 3, id);
        if (!run(s))
            return rollback(a);
    }
    if (!cancel) {
        s = stmt(
            a,
            "INSERT INTO desk_reservations(worker_id,desk_id,reservation_date,start_time,end_time) "
            "VALUES(?,?,?,?,?)");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, u->id);
        sqlite3_bind_int(s, 2, desk);
        bind_text(s, 3, date);
        bind_text(s, 4, start);
        bind_text(s, 5, end);
        if (!run(s))
            return rollback(a);
    }
    if (!execsql(a, "COMMIT"))
        return rollback(a);
    return ok();
}
static Result desk_manage(App *a, json_object *j, int edit, int remove) {
    int id = num(j, "id");
    const char *name = str(j, "name", 240);
    if (remove) {
        if (id < 1 || boolean(j, "confirm") != 1)
            return fail(400, "confirmation_required", "יש לאשר את מחיקת העמדה וכל ההזמנות שלה.");
        if (!execsql(a, "BEGIN IMMEDIATE"))
            return dberr(a);
        sqlite3_stmt *s = stmt(a, "DELETE FROM desk_reservations WHERE desk_id=?");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, id);
        if (!run(s))
            return rollback(a);
        s = stmt(a, "DELETE FROM desks WHERE id=?");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, id);
        if (!run(s) || !execsql(a, "COMMIT"))
            return rollback(a);
        return ok();
    }
    if (!textvalid(name, 1) || !textwithin(name, 60) || (edit && id < 1))
        return fail(400, "invalid_desk", "בחרו שם לעמדה.");
    sqlite3_stmt *s =
        stmt(a, edit ? "UPDATE desks SET name=? WHERE id=?" : "INSERT INTO desks(name) VALUES(?)");
    if (!s)
        return dberr(a);
    bind_text(s, 1, name);
    if (edit)
        sqlite3_bind_int(s, 2, id);
    if (!run(s))
        return dberr(a);
    return ok();
}

static Result profile_update(App *a, const Identity *u, json_object *j) {
    const char *first = str(j, "first_name", 240), *last = str(j, "last_name", 240),
               *gender = str(j, "gender", 8), *birth = str(j, "birth_date", 10);
    char now[11], name[482];
    today(now);
    if (!textvalid(first, 1) || !textwithin(first, 60) || !textvalid(last, 1) ||
        !textwithin(last, 60) || !gender ||
        (strcmp(gender, "male") && strcmp(gender, "female") && strcmp(gender, "other")) ||
        (birth && *birth && (!datevalid(birth, NULL) || strcmp(birth, now) > 0)))
        return fail(400, "invalid_profile", "בדקו את השם, המגדר ותאריך הלידה.");
    snprintf(name, sizeof name, "%s %s", first, last);
    sqlite3_stmt *s = stmt(
        a, "UPDATE users SET first_name=?,last_name=?,name=?,gender=?,birth_date=? WHERE id=?");
    if (!s)
        return dberr(a);
    bind_text(s, 1, first);
    bind_text(s, 2, last);
    bind_text(s, 3, name);
    bind_text(s, 4, gender);
    if (birth && *birth)
        bind_text(s, 5, birth);
    else
        sqlite3_bind_null(s, 5);
    sqlite3_bind_int(s, 6, u->id);
    if (!run(s))
        return dberr(a);
    return ok();
}
static Result account_action(App *a, const Identity *u, json_object *j, const char *action) {
    int id = num(j, "id");
    const char *pw = str(j, "password", 128);
    char hash[crypto_pwhash_STRBYTES] = {0};
    int reset = !strcmp(action, "reset-password"), deactivate = !strcmp(action, "deactivate"),
        remove = !strcmp(action, "delete"), reactivate = !strcmp(action, "reactivate");
    if (id < 1 || (!reset && !deactivate && !remove && !reactivate))
        return fail(400, "invalid_user", "בחרו חשבון.");
    if ((deactivate || remove) && boolean(j, "confirm") != 1)
        return fail(400, "confirmation_required", "יש לאשר את הפעולה.");
    if (reset) {
        if (id == u->id)
            return fail(403, "own_password", "שנו את הסיסמה האישית דרך הפרופיל.");
        if (!password_valid(pw) || !str(j, "password_confirmation", 128) ||
            strcmp(pw, str(j, "password_confirmation", 128)))
            return fail(400, "invalid_password", "הסיסמה חייבת להכיל לפחות 12 תווים.");
        if (!hashpassword(pw, hash))
            return fail(503, "busy", "נסו שוב בעוד רגע.");
    }
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    sqlite3_stmt *s = stmt(a, "SELECT role,active,(SELECT count(*) FROM users WHERE role='admin' "
                              "AND active=1) FROM users WHERE id=?");
    if (!s)
        return rollback(a);
    sqlite3_bind_int(s, 1, id);
    int rc = sqlite3_step(s);
    if (rc != SQLITE_ROW) {
        sqlite3_finalize(s);
        execsql(a, "ROLLBACK");
        return fail(404, "not_found", "החשבון לא נמצא.");
    }
    int active = sqlite3_column_int(s, 1),
        last_manager = !strcmp(col(s, 0), "admin") && active && sqlite3_column_int(s, 2) <= 1;
    sqlite3_finalize(s);
    if ((deactivate && last_manager) || (remove && active)) {
        execsql(a, "ROLLBACK");
        return fail(409, "account_protected",
                    "אי אפשר להשבית מנהל אחרון. לפני מחיקה יש להשבית את החשבון.");
    }
    if ((deactivate && !active) || (reactivate && active)) {
        execsql(a, "ROLLBACK");
        return ok();
    }
    if (deactivate) {
        char date[11], clock[6];
        today(date);
        time_t now = time(NULL);
        struct tm t;
        localtime_r(&now, &t);
        strftime(clock, sizeof clock, "%H:%M", &t);
        /* Keep completed shifts. All categories end at their configured time. */
        for (int i = 0; i < 5; i++) {
            s = stmt(a, "UPDATE shifts SET "
                        "cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason='worker_"
                        "deactivated' WHERE worker_id=? AND cancelled_at IS NULL AND period=? AND "
                        "(shift_date>? OR (shift_date=? AND ?>?))");
            if (!s)
                return rollback(a);
            sqlite3_bind_int(s, 1, u->id);
            sqlite3_bind_int(s, 2, id);
            bind_text(s, 3, periods[i].code);
            bind_text(s, 4, date);
            bind_text(s, 5, date);
            bind_text(s, 6, periods[i].end);
            bind_text(s, 7, clock);
            if (!run(s))
                return rollback(a);
        }
        s = stmt(a, "UPDATE desk_reservations SET "
                    "cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason='worker_"
                    "deactivated' WHERE worker_id=? AND cancelled_at IS NULL AND "
                    "(reservation_date>? OR (reservation_date=? AND end_time>?))");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, u->id);
        sqlite3_bind_int(s, 2, id);
        bind_text(s, 3, date);
        bind_text(s, 4, date);
        bind_text(s, 5, clock);
        if (!run(s))
            return rollback(a);
    }
    if (remove) {
        const char *queries[] = {"DELETE FROM shifts WHERE worker_id=?",
                                 "DELETE FROM desk_reservations WHERE worker_id=?",
                                 "DELETE FROM receipts WHERE worker_id=?",
                                 "DELETE FROM users WHERE id=?"};
        for (size_t i = 0; i < 4; i++) {
            s = stmt(a, queries[i]);
            if (!s)
                return rollback(a);
            sqlite3_bind_int(s, 1, id);
            if (!run(s))
                return rollback(a);
        }
    } else if (reset) {
        s = stmt(a, "UPDATE users SET password=?,must_change=0 WHERE id=?");
        if (!s)
            return rollback(a);
        bind_text(s, 1, hash);
        sqlite3_bind_int(s, 2, id);
        if (!run(s))
            return rollback(a);
        sodium_memzero(hash, sizeof hash);
    } else {
        s = stmt(a, "UPDATE users SET active=? WHERE id=?");
        if (!s)
            return rollback(a);
        sqlite3_bind_int(s, 1, reactivate);
        sqlite3_bind_int(s, 2, id);
        if (!run(s))
            return rollback(a);
    }
    s = stmt(a, "DELETE FROM sessions WHERE user_id=?");
    if (!s)
        return rollback(a);
    sqlite3_bind_int(s, 1, id);
    if (!run(s) || !execsql(a, "COMMIT"))
        return rollback(a);
    audit(a, u->id, action, id);
    return ok();
}
static int month_valid(const char *month) {
    char date[11];
    if (!month || strlen(month) != 7)
        return 0;
    snprintf(date, sizeof date, "%s-01", month);
    return datevalid(date, NULL);
}
static Result receipt_write(App *a, const Identity *u, json_object *j) {
    const char *month = str(j, "month", 7);
    int has = boolean(j, "has_receipts");
    char now[11];
    today(now);
    if (u->admin)
        return fail(403, "worker_only", "הדיווח זמין למשתתפים בלבד.");
    if (!month_valid(month) || strncmp(month, now, 7) || has < 0)
        return fail(400, "invalid_month", "אפשר לעדכן קבלות רק לחודש הנוכחי.");
    sqlite3_stmt *s =
        stmt(a, "INSERT INTO receipts(worker_id,month,has_receipts) VALUES(?,?,?) ON "
                "CONFLICT(worker_id,month) DO UPDATE SET has_receipts=excluded.has_receipts");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, month);
    sqlite3_bind_int(s, 3, has);
    if (!run(s))
        return dberr(a);
    return ok();
}
static Result receipts_view(App *a, const Identity *u, struct MHD_Connection *c) {
    const char *month = MHD_lookup_connection_value(c, MHD_GET_ARGUMENT_KIND, "month");
    if (!month_valid(month))
        return fail(400, "invalid_month", "בחרו חודש תקין.");
    sqlite3_stmt *s = stmt(a, "SELECT u.id,u.name,coalesce(r.has_receipts,0) AS has_receipts FROM "
                              "users u LEFT JOIN receipts r ON r.worker_id=u.id AND r.month=? "
                              "WHERE u.role='member' AND (?=1 OR u.id=?) ORDER BY u.name,u.id");
    if (!s)
        return dberr(a);
    bind_text(s, 1, month);
    sqlite3_bind_int(s, 2, u->admin);
    sqlite3_bind_int(s, 3, u->id);
    return array_result("receipts", s, a);
}
static Result event_write(App *a, json_object *j, int edit, int remove) {
    int id = num(j, "id");
    const char *date = str(j, "date", 10), *title = str(j, "title", 400), *kind = str(j, "kind", 8),
               *description = str(j, "description", 2000);
    if (remove) {
        sqlite3_stmt *s = stmt(a, "DELETE FROM events WHERE id=?");
        if (!s)
            return dberr(a);
        sqlite3_bind_int(s, 1, id);
        if (!run(s))
            return dberr(a);
        return ok();
    }
    if (!datevalid(date, NULL) || !textvalid(title, 1) || !textwithin(title, 100) || !kind ||
        (strcmp(kind, "event") && strcmp(kind, "holiday")) ||
        (description && (!textvalid(description, 0) || !textwithin(description, 500))) ||
        (edit && id < 1))
        return fail(400, "invalid_event", "בדקו את התאריך, הכותרת וסוג האירוע.");
    sqlite3_stmt *s =
        stmt(a, edit ? "UPDATE events SET date=?,title=?,kind=?,description=? WHERE id=?"
                     : "INSERT INTO events(date,title,kind,description) VALUES(?,?,?,?)");
    if (!s)
        return dberr(a);
    bind_text(s, 1, date);
    bind_text(s, 2, title);
    bind_text(s, 3, kind);
    bind_text(s, 4, description ? description : "");
    if (edit)
        sqlite3_bind_int(s, 5, id);
    if (!run(s))
        return dberr(a);
    return ok();
}
static Result today_view(App *a) {
    char now[11];
    today(now);
    json_object *o = json_object_new_object();
    js(o, "date", now);
    sqlite3_stmt *s = stmt(a, "SELECT u.id,u.name,s.period FROM users u JOIN shifts s ON "
                              "s.worker_id=u.id WHERE u.active=1 AND u.role='member' AND "
                              "s.shift_date=? AND s.cancelled_at IS NULL ORDER BY u.name,u.id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, now);
    json_object *arr = rows(s);
    if (!arr) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "coworkers", arr);
    s = stmt(a, "SELECT id,date,title,kind,description FROM events WHERE date=? ORDER BY id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, now);
    arr = rows(s);
    if (!arr) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "events", arr);
    int year = atoi(now), leap = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0);
    s = stmt(a,
             "SELECT id,name FROM users WHERE active=1 AND role='member' AND "
             "(substr(birth_date,6)=? OR (?=1 AND substr(birth_date,6)='02-29')) ORDER BY name,id");
    if (!s) {
        json_object_put(o);
        return dberr(a);
    }
    bind_text(s, 1, now + 5);
    sqlite3_bind_int(s, 2, !leap && !strcmp(now + 5, "02-28"));
    arr = rows(s);
    if (!arr) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "birthdays", arr);
    return result(200, o);
}
/* A manager's report is generated only by this explicit POST. Cells are quoted
 * and spreadsheet formulas are neutralized before the download is produced. */
static void csv_cell(FILE *f, const char *value) {
    fputc('"', f);
    const char *p = value;
    while (*p == ' ' || *p == '\t' || *p == '\r' || *p == '\n')
        p++;
    if (*p == '=' || *p == '+' || *p == '-' || *p == '@' || *value == '\t' || *value == '\r')
        fputc('\'', f);
    for (p = value; *p; p++) {
        if (*p == '"')
            fputc('"', f);
        fputc(*p, f);
    }
    fputc('"', f);
}
static Result workday_report(App *a, json_object *j) {
    const char *from = str(j, "from", 10), *to = str(j, "to", 10), *month = str(j, "month", 7);
    char now[11];
    today(now);
    if (!datevalid(from, NULL) || !datevalid(to, NULL) || strcmp(from, to) > 0 ||
        strcmp(to, now) > 0 || !month_valid(month))
        return fail(400, "invalid_range", "בחרו טווח תאריכים עד היום וחודש קבלות.");
    sqlite3_stmt *s =
        stmt(a, "SELECT u.id,u.name,coalesce(r.has_receipts,0) FROM users u LEFT JOIN receipts r "
                "ON r.worker_id=u.id AND r.month=? WHERE u.role='member' ORDER BY u.name,u.id");
    if (!s)
        return dberr(a);
    bind_text(s, 1, month);
    char *buffer = NULL;
    size_t length = 0;
    FILE *f = open_memstream(&buffer, &length);
    if (!f) {
        sqlite3_finalize(s);
        return fail(503, "unavailable", "נסו שוב.");
    }
    fputs("\xef\xbb\xbf\"שם העובד\",\"מספר ימי עבודה\",\"תאריכי עבודה\",\"קבלות\"\r\n", f);
    int rc;
    while ((rc = sqlite3_step(s)) == SQLITE_ROW) {
        sqlite3_stmt *d =
            stmt(a, "SELECT shift_date FROM shifts WHERE worker_id=? AND cancelled_at IS NULL AND "
                    "shift_date BETWEEN ? AND ? ORDER BY shift_date");
        if (!d) {
            rc = SQLITE_ERROR;
            break;
        }
        sqlite3_bind_int(d, 1, sqlite3_column_int(s, 0));
        bind_text(d, 2, from);
        bind_text(d, 3, to);
        char *dates = NULL;
        size_t size = 0;
        FILE *df = open_memstream(&dates, &size);
        if (!df) {
            sqlite3_finalize(d);
            rc = SQLITE_NOMEM;
            break;
        }
        int count = 0, dr;
        while ((dr = sqlite3_step(d)) == SQLITE_ROW) {
            const char *date = col(d, 0);
            fprintf(df, "%s%.2s/%.2s/%.4s", count ? ", " : "", date + 8, date + 5, date);
            count++;
        }
        sqlite3_finalize(d);
        fclose(df);
        if (dr != SQLITE_DONE) {
            free(dates);
            rc = dr;
            break;
        }
        char count_text[32];
        snprintf(count_text, sizeof count_text, "%d", count);
        csv_cell(f, col(s, 1));
        fputc(',', f);
        csv_cell(f, count_text);
        fputc(',', f);
        csv_cell(f, dates ? dates : "");
        fputc(',', f);
        csv_cell(f, sqlite3_column_int(s, 2) ? "יש" : "אין");
        fputs("\r\n", f);
        free(dates);
    }
    sqlite3_finalize(s);
    int error = ferror(f);
    fclose(f);
    if (rc != SQLITE_DONE || error) {
        free(buffer);
        return dberr(a);
    }
    Result r = {0};
    r.status = 200;
    r.body = buffer;
    r.body_size = length;
    r.content_type = "text/csv; charset=utf-8";
    return r;
}

static Result signup_status(App *a, const char *role) {
    sqlite3_stmt *s = stmt(a, "SELECT enabled FROM signup_settings WHERE role=?");
    if (!s)
        return dberr(a);
    bind_text(s, 1, role);
    int rc = sqlite3_step(s);
    if (rc != SQLITE_ROW) {
        sqlite3_finalize(s);
        return dberr(a);
    }
    Result r = ok();
    json_object_object_add(r.json, "enabled", json_object_new_boolean(sqlite3_column_int(s, 0)));
    sqlite3_finalize(s);
    return r;
}
static Result signup_submit(App *a, struct MHD_Connection *c, json_object *j, const char *role) {
    char login_buffer[61];
    const char *login_name = normalized_login(str(j, "login", 120), login_buffer),
               *first = str(j, "first_name", 240), *last = str(j, "last_name", 240),
               *gender = str(j, "gender", 8), *birth = str(j, "birth_date", 10),
               *pw = str(j, "password", 128), *confirmation = str(j, "password_confirmation", 128);
    char now[11];
    today(now);
    if (!loginvalid(login_name) || strlen(login_name) < 3 || !textvalid(first, 1) ||
        !textwithin(first, 60) || !textvalid(last, 1) || !textwithin(last, 60) || !gender ||
        (strcmp(gender, "male") && strcmp(gender, "female") && strcmp(gender, "other")) ||
        (birth && *birth && (!datevalid(birth, NULL) || strcmp(birth, now) > 0)) ||
        !password_valid(pw) || !confirmation || strcmp(pw, confirmation))
        return fail(400, "invalid_signup", "בדקו את הפרטים ואת אימות הסיסמה (לפחות 12 תווים).");
    if (!hash_budget(a, c, 1.0))
        return fail(429, "busy", "נסו שוב בעוד רגע.");
    char hash[crypto_pwhash_STRBYTES];
    if (!hashpassword(pw, hash))
        return fail(503, "busy", "נסו שוב.");
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    sqlite3_stmt *s = stmt(a, "SELECT enabled FROM signup_settings WHERE role=?");
    if (!s)
        return rollback(a);
    bind_text(s, 1, role);
    int enabled = sqlite3_step(s) == SQLITE_ROW && sqlite3_column_int(s, 0);
    sqlite3_finalize(s);
    if (!enabled) {
        execsql(a, "ROLLBACK");
        return fail(403, "signup_closed", "ההרשמה סגורה כעת");
    }
    s = stmt(
        a,
        "SELECT 1 FROM users WHERE login=? UNION ALL SELECT 1 FROM pending_signups WHERE login=?");
    if (!s)
        return rollback(a);
    bind_text(s, 1, login_name);
    bind_text(s, 2, login_name);
    int rc = sqlite3_step(s);
    sqlite3_finalize(s);
    if (rc == SQLITE_ROW) {
        execsql(a, "ROLLBACK");
        return fail(409, "username_taken", "לא ניתן להשתמש בשם המשתמש הזה.");
    }
    if (rc != SQLITE_DONE)
        return rollback(a);
    s = stmt(
        a,
        "INSERT INTO pending_signups(login,first_name,last_name,gender,birth_date,role,password) "
        "VALUES(?,?,?,?,?,?,?)");
    if (!s)
        return rollback(a);
    bind_text(s, 1, login_name);
    bind_text(s, 2, first);
    bind_text(s, 3, last);
    bind_text(s, 4, gender);
    if (birth && *birth)
        bind_text(s, 5, birth);
    else
        sqlite3_bind_null(s, 5);
    bind_text(s, 6, role);
    bind_text(s, 7, hash);
    int success = run(s);
    sodium_memzero(hash, sizeof hash);
    if (!success || !execsql(a, "COMMIT"))
        return rollback(a);
    Result r = ok();
    js(r.json, "message",
       "הבקשה נשלחה וממתינה לאישור. צרו קשר עם המנהל/ת לאישור חשבון המשתמש שלכם.");
    return r;
}
static Result signup_setting(App *a, json_object *j) {
    const char *role = str(j, "role", 8);
    int enabled = boolean(j, "enabled");
    if (!role || (strcmp(role, "admin") && strcmp(role, "member")) || enabled < 0)
        return fail(400, "invalid_setting", "בחרו סוג חשבון ומצב הרשמה.");
    sqlite3_stmt *s = stmt(a, "UPDATE signup_settings SET enabled=? WHERE role=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, enabled);
    bind_text(s, 2, role);
    if (!run(s))
        return dberr(a);
    return ok();
}
static Result signup_list(App *a) {
    json_object *o = json_object_new_object();
    json_object *arr = rows(stmt(a, "SELECT role,enabled FROM signup_settings ORDER BY role"));
    if (!arr) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "settings", arr);
    arr = rows(stmt(
        a, "SELECT id,login,first_name,last_name,gender,birth_date,role,created_at,review_error "
           "FROM pending_signups ORDER BY id"));
    if (!arr) {
        json_object_put(o);
        return dberr(a);
    }
    json_object_object_add(o, "requests", arr);
    return result(200, o);
}
/* Freeze the exact cohort and bind it to this manager's current session. A
 * later arrival cannot enter a previously confirmed bulk operation. */
static Result signup_review(App *a, const Identity *u, json_object *j) {
    const char *action = str(j, "action", 8);
    int id = num(j, "id");
    if (!action || (strcmp(action, "approve") && strcmp(action, "delete")))
        return fail(400, "invalid_action", "בחרו פעולה.");
    sqlite3_stmt *s =
        stmt(a, "SELECT id,role FROM pending_signups WHERE (?<1 OR id=?) ORDER BY id");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, id);
    sqlite3_bind_int(s, 2, id);
    json_object *ids = json_object_new_array();
    int workers = 0, managers = 0, rc;
    while ((rc = sqlite3_step(s)) == SQLITE_ROW) {
        json_object_array_add(ids, json_object_new_int(sqlite3_column_int(s, 0)));
        if (!strcmp(col(s, 1), "admin"))
            managers++;
        else
            workers++;
    }
    sqlite3_finalize(s);
    if (rc != SQLITE_DONE) {
        json_object_put(ids);
        return dberr(a);
    }
    char token[65], hash[65];
    hexrandom(token);
    digest(token, hash);
    s = stmt(a, "INSERT INTO signup_reviews(token,actor_id,session_hash,action,ids,expires) "
                "VALUES(?,?,?,?,?,?)");
    if (!s) {
        json_object_put(ids);
        return dberr(a);
    }
    bind_text(s, 1, hash);
    sqlite3_bind_int(s, 2, u->id);
    bind_text(s, 3, u->session_hash);
    bind_text(s, 4, action);
    bind_text(s, 5, json_object_to_json_string_ext(ids, JSON_C_TO_STRING_PLAIN));
    sqlite3_bind_int64(s, 6, (sqlite3_int64)time(NULL) + 1800);
    json_object_put(ids);
    if (!run(s))
        return dberr(a);
    Result r = ok();
    js(r.json, "token", token);
    ji(r.json, "workers", workers);
    ji(r.json, "managers", managers);
    return r;
}
static Result signup_process(App *a, const Identity *u, json_object *j) {
    const char *token = str(j, "token", 64);
    if (!token || strlen(token) != 64)
        return fail(400, "invalid_review", "יש לאשר את הפעולה מחדש.");
    char hash[65];
    digest(token, hash);
    if (!execsql(a, "BEGIN IMMEDIATE"))
        return dberr(a);
    sqlite3_stmt *s = stmt(a, "SELECT action,ids FROM signup_reviews WHERE token=? AND actor_id=? "
                              "AND session_hash=? AND expires>?");
    if (!s)
        return rollback(a);
    bind_text(s, 1, hash);
    sqlite3_bind_int(s, 2, u->id);
    bind_text(s, 3, u->session_hash);
    sqlite3_bind_int64(s, 4, (sqlite3_int64)time(NULL));
    int rc = sqlite3_step(s);
    if (rc != SQLITE_ROW) {
        sqlite3_finalize(s);
        execsql(a, "ROLLBACK");
        return fail(409, "review_expired", "יש לאשר את הפעולה מחדש.");
    }
    int approve = !strcmp(col(s, 0), "approve");
    json_object *ids = json_tokener_parse(col(s, 1));
    sqlite3_finalize(s);
    if (!ids || !json_object_is_type(ids, json_type_array)) {
        if (ids)
            json_object_put(ids);
        return rollback(a);
    }
    int processed = 0, conflicts = 0;
    for (size_t i = 0; i < json_object_array_length(ids); i++) {
        int id = json_object_get_int(json_object_array_get_idx(ids, i));
        if (approve) {
            s = stmt(
                a, "SELECT 1 FROM pending_signups p JOIN users u ON u.login=p.login WHERE p.id=?");
            if (!s)
                goto error;
            sqlite3_bind_int(s, 1, id);
            rc = sqlite3_step(s);
            sqlite3_finalize(s);
            if (rc == SQLITE_ROW) {
                s = stmt(a, "UPDATE pending_signups SET review_error='שם המשתמש כבר קיים במערכת.' "
                            "WHERE id=?");
                if (!s)
                    goto error;
                sqlite3_bind_int(s, 1, id);
                if (!run(s))
                    goto error;
                conflicts++;
                continue;
            }
            if (rc != SQLITE_DONE)
                goto error;
            s = stmt(a, "INSERT INTO "
                        "users(login,name,first_name,last_name,gender,birth_date,role,password) "
                        "SELECT login,first_name||' "
                        "'||last_name,first_name,last_name,gender,birth_date,role,password FROM "
                        "pending_signups WHERE id=?");
            if (!s)
                goto error;
            sqlite3_bind_int(s, 1, id);
            if (!run(s))
                goto error;
        }
        s = stmt(a, "DELETE FROM pending_signups WHERE id=?");
        if (!s)
            goto error;
        sqlite3_bind_int(s, 1, id);
        if (!run(s))
            goto error;
        processed += sqlite3_changes(a->db);
    }
    s = stmt(a, "DELETE FROM signup_reviews WHERE token=? OR expires<=?");
    if (!s)
        goto error;
    bind_text(s, 1, hash);
    sqlite3_bind_int64(s, 2, (sqlite3_int64)time(NULL));
    if (!run(s) || !execsql(a, "COMMIT"))
        goto error;
    json_object_put(ids);
    Result r = ok();
    ji(r.json, "processed", processed);
    ji(r.json, "conflicts", conflicts);
    return r;
error:
    json_object_put(ids);
    return rollback(a);
}

static Result wolfe_command(App *a, const Identity *u, json_object *j) {
    const char *text = str(j, "text", 512), *start = str(j, "week_start", 10);
    int weekday, has_text = 0;
    if (text)
        for (const unsigned char *p = (const unsigned char *)text; *p; p++)
            if (*p > 32)
                has_text = 1;
    if (!has_text || !textvalid(text, 1) || !datevalid(start, &weekday) || weekday != 0)
        return fail(400, "invalid_command", "כתבו בקשה קצרה ובחרו שבוע תקין.");

    if (strstr(text, "מהבית") || strstr(text, "בבית") || strstr(text, "מרחוק"))
        return fail(400, "center_only", "כרגע נרשמים להגעה למרכז בלבד. באיזה יום ובאילו שעות?");
    if (!strcmp(text, "שלום") || !strcmp(text, "היי") || !strcmp(text, "הי") ||
        !strcmp(text, "שלום איציק") || !strcmp(text, "היי איציק") || !strcmp(text, "בוקר טוב") ||
        !strcmp(text, "שלום איציק!")) {
        Result r = ok();
        js(r.json, "message",
           "אהלן! איציק כאן 🙂 אפשר לבקש לוח שבועי, או לכתוב למשל: תרשום אותי ביום ראשון מ-9 עד "
           "13.");
        return r;
    }
    if (!strcmp(text, "עזרה") || !strcmp(text, "מה אתה יכול לעשות") ||
        !strcmp(text, "מה אתה יכול לעשות?") || !strcmp(text, "איך נרשמים")) {
        Result r = ok();
        js(r.json, "message",
           u->admin ? "אפשר לבקש ממני את הלוח השבועי של כולם 🙂 הרשמה למשמרות נעשית מחשבון משתתף; "
                      "חשבונות חדשים יוצרים בניהול ← משתמשים."
                    : "אפשר לבקש לראות את השבוע או להירשם למשמרת 🙂 למשל: קבע לי ביום ראשון מ-9 עד "
                      "13. אם חסרות שעות אשאל, ולפני שמירה אבקש אישור.");
        return r;
    }
    if (!strcmp(text, "תודה") || !strcmp(text, "תודה איציק")) {
        Result r = ok();
        js(r.json, "message", "בשמחה! נתראה במרכז 🙂");
        return r;
    }
    char buffer[4096];
    if (wolfe_call(a->wolf, text, WOLFE_NEURAL, WOLFE_REASONING_OFF, buffer, sizeof buffer, NULL) !=
        WOLFE_OK)
        return fail(400, "wolfe_input", "נסו בקשה קצרה יותר עם יום ושעות.");
    json_object *decision = json_tokener_parse(buffer);
    const char *status = str(decision, "status", 32);
    if (!status || (strcmp(status, "call") && strcmp(status, "no_call") &&
                    strcmp(status, "ambiguous") && strcmp(status, "missing_arguments"))) {
        json_object_put(decision);
        return fail(500, "wolfe_error", "לא התקבלה תשובה תקינה מאיציק.");
    }
    /* Explain the role before asking for details of a booking the manager
     * cannot make. Wolfe exposes the selected tool for incomplete requests too. */
    const char *intent = str(decision, "tool", 40);
    json_object *intent_calls = NULL;
    if (!strcmp(status, "call") && json_object_object_get_ex(decision, "calls", &intent_calls) &&
        json_object_is_type(intent_calls, json_type_array) &&
        json_object_array_length(intent_calls) == 1)
        intent = str(json_object_array_get_idx(intent_calls, 0), "name", 40);
    if (u->admin && intent && !strcmp(intent, "propose_booking") &&
        (!strcmp(status, "call") || !strcmp(status, "missing_arguments"))) {
        json_object_put(decision);
        return fail(403, "worker_only",
                    "הבנתי את בקשת ההרשמה 🙂 כרגע מחובר חשבון מנהל. כדי להירשם למשמרת, יש להיכנס "
                    "עם חשבון משתתף. כאן אוכל להציג את הלוח השבועי של כולם.");
    }
    json_object *out = json_object_new_object();
    js(out, "engine", "wolfe");
    js(out, "status", status);
    json_object_object_add(out, "action", NULL);
    json_object_object_add(out, "proposal", NULL);
    js(out, "message",
       !strcmp(status, "ambiguous") ? "יש כמה אפשרויות. נסו לפרט את הבקשה."
       : !strcmp(status, "missing_arguments")
           ? "באיזה יום ובאילו שעות? אפשר \u206609–13\u2069, \u206610–14\u2069, \u206611–15\u2069, "
             "\u206612–16\u2069 או יום מלא."
           : "לא הצלחתי לזהות בקשה לביצוע. אפשר לבקש לוח שבועי או לכתוב, למשל: קבע לי ביום ראשון "
             "מ-9 עד 13. ההרשמה נשמרת רק אחרי אישור.");
    if (strcmp(status, "call")) {
        json_object *missing = NULL;
        if (json_object_object_get_ex(decision, "missing", &missing) &&
            json_object_is_type(missing, json_type_array)) {
            json_object_object_add(out, "missing", json_object_get(missing));
            if (json_object_array_length(missing) == 1) {
                const char *field = json_object_get_string(json_object_array_get_idx(missing, 0));
                if (field && !strcmp(field, "location"))
                    js(out, "message", "איפה לומדים — מהבית או בחממה?");
                else if (field && !strcmp(field, "period"))
                    js(out, "message",
                       "באילו שעות — \u206609–13\u2069, \u206610–14\u2069, \u206611–15\u2069, "
                       "\u206612–16\u2069 או יום מלא?");
                else if (field && !strcmp(field, "day"))
                    js(out, "message", "באיזה יום — ראשון עד חמישי, היום או מחר?");
            }
        }
        json_object_put(decision);
        return result(200, out);
    }
    json_object *calls = NULL, *args = NULL;
    if (!json_object_object_get_ex(decision, "calls", &calls) ||
        !json_object_is_type(calls, json_type_array) || json_object_array_length(calls) != 1)
        goto invalid;
    json_object *call = json_object_array_get_idx(calls, 0);
    const char *name = str(call, "name", 40);
    if (!name || !json_object_object_get_ex(call, "arguments", &args) ||
        !json_object_is_type(args, json_type_object))
        goto invalid;
    if (!strcmp(name, "show_schedule") || !strcmp(name, "show_my_bookings")) {
        if (json_object_object_length(args) != 0)
            goto invalid;
        js(out, "action", name);
        js(out, "message", u->admin ? "הנה לוח השבוע של כולם." : "הנה המשמרות שלך השבוע.");
    } else if (!strcmp(name, "propose_booking")) {
        const char *day = str(args, "day", 16), *period = str(args, "period", 12),
                   *which = str(args, "week", 8);
        int index = period_index(period);
        if (!day || index < 0 || (which && strcmp(which, "selected") && strcmp(which, "next")))
            goto invalid;
        int next = which && !strcmp(which, "next");
        char date[11], now[11];
        today(now);
        if (!strcmp(day, "today") || !strcmp(day, "tomorrow")) {
            if (next) {
                js(out, "status", "ambiguous");
                js(out, "message", "לבקשה בשבוע הבא צריך לציין את היום בשבוע.");
                json_object_put(decision);
                return result(200, out);
            }
            if (!adddays(now, !strcmp(day, "tomorrow"), date))
                goto invalid;
        } else {
            const char *days[] = {"sunday", "monday", "tuesday", "wednesday", "thursday"};
            int offset = -1;
            for (int i = 0; i < 5; i++)
                if (!strcmp(day, days[i]))
                    offset = i;
            if (offset < 0 || !adddays(start, offset + (next ? 7 : 0), date))
                goto invalid;
        }
        if (strcmp(date, now) < 0) {
            char message[1024];
            snprintf(message, sizeof message,
                     "התאריך \u2066%.2s/%.2s/%.4s\u2069 בשבוע שמוצג כבר עבר. "
                     "שמרתי את היום ואת השעות \u2066%s–%s\u2069. "
                     "אפשר לכתוב ״בשבוע הבא״ כדי להמשיך בלי להתחיל מחדש.",
                     date + 8, date + 5, date, periods[index].start, periods[index].end);
            json_object_put(out);
            json_object_put(decision);
            return fail(409, "past_booking_date", message);
        }
        if (!editable_date(date)) {
            json_object_put(out);
            json_object_put(decision);
            return fail(409, "date_unavailable",
                        "בחרו יום ראשון עד חמישי, מהיום ועד ארבעה שבועות קדימה.");
        }
        if (u->admin) {
            json_object_put(out);
            json_object_put(decision);
            return fail(403, "worker_only", "למנהלים יש לוח משותף; הרשמה למשמרת זמינה למשתתפים.");
        }
        int blocked = closed(a, date);
        if (blocked) {
            json_object_put(out);
            json_object_put(decision);
            return blocked > 0 ? fail(409, "shift_closed", "המרכז סגור ביום הזה.") : dberr(a);
        }
        json_object *proposal = json_object_new_object();
        js(proposal, "date", date);
        js(proposal, "period", period);
        js(out, "action", name);
        json_object_object_add(out, "proposal", proposal);
        js(out, "message", "בדקו את התאריך והמשמרת ואשרו כדי לשמור.");
    } else
        goto invalid;
    json_object_put(decision);
    return result(200, out);
invalid:
    json_object_put(out);
    json_object_put(decision);
    return fail(500, "wolfe_error", "לא התקבלה פעולה תקינה מאיציק.");
}

/* Avatars are bounded raster blobs, served only through the authenticated API.
 * Dimensions are read with checked bounds; SVG/HTML and animated WebP are excluded. */
static unsigned be16(const unsigned char *p) { return ((unsigned)p[0] << 8) | p[1]; }
static unsigned be32(const unsigned char *p) {
    return ((unsigned)p[0] << 24) | ((unsigned)p[1] << 16) | ((unsigned)p[2] << 8) | p[3];
}
static unsigned le24(const unsigned char *p) {
    return p[0] | ((unsigned)p[1] << 8) | ((unsigned)p[2] << 16);
}
static unsigned le32(const unsigned char *p) { return le24(p) | ((unsigned)p[3] << 24); }
static const char *raster_type(const unsigned char *p, size_t n) {
    unsigned w = 0, h = 0;
    const char *type = NULL;
    if (n >= 45 && !memcmp(p, "\x89PNG\r\n\x1a\n", 8) && be32(p + 8) == 13 &&
        !memcmp(p + 12, "IHDR", 4)) {
        w = be32(p + 16);
        h = be32(p + 20);
        type = "image/png";
        size_t off = 8;
        int data = 0, end = 0;
        while (off + 12 <= n) {
            size_t size = be32(p + off);
            if (size > n - off - 12)
                return NULL;
            if (!memcmp(p + off + 4, "IDAT", 4))
                data = 1;
            if (!memcmp(p + off + 4, "IEND", 4)) {
                end = size == 0 && off + 12 == n;
                break;
            }
            off += size + 12;
        }
        if (!data || !end)
            return NULL;
    } else if (n >= 12 && p[0] == 255 && p[1] == 216 && p[n - 2] == 255 && p[n - 1] == 217) {
        size_t off = 2;
        while (off + 4 <= n) {
            if (p[off++] != 255)
                return NULL;
            while (off < n && p[off] == 255)
                off++;
            if (off >= n)
                return NULL;
            unsigned marker = p[off++];
            if (marker == 0xda)
                break;
            if (marker == 0xd8 || marker == 0xd9 || marker == 0x01 ||
                (marker >= 0xd0 && marker <= 0xd7))
                continue;
            if (off + 2 > n)
                return NULL;
            size_t size = be16(p + off);
            if (size < 2 || size > n - off)
                return NULL;
            if (marker >= 0xc0 && marker <= 0xcf && marker != 0xc4 && marker != 0xc8 &&
                marker != 0xcc) {
                if (size < 8)
                    return NULL;
                h = be16(p + off + 3);
                w = be16(p + off + 5);
                type = "image/jpeg";
                break;
            }
            off += size;
        }
    } else if (n >= 30 && !memcmp(p, "RIFF", 4) && !memcmp(p + 8, "WEBP", 4) &&
               le32(p + 4) == n - 8) {
        if (!memcmp(p + 12, "VP8X", 4) && le32(p + 16) == 10 && !(p[20] & 2)) {
            w = le24(p + 24) + 1;
            h = le24(p + 27) + 1;
        } else if (!memcmp(p + 12, "VP8 ", 4) && le32(p + 16) <= n - 20 &&
                   !memcmp(p + 23, "\x9d\x01\x2a", 3)) {
            w = (p[26] | ((unsigned)p[27] << 8)) & 16383;
            h = (p[28] | ((unsigned)p[29] << 8)) & 16383;
        } else if (!memcmp(p + 12, "VP8L", 4) && le32(p + 16) <= n - 20 && p[20] == 0x2f) {
            unsigned bits = le32(p + 21);
            w = (bits & 16383) + 1;
            h = ((bits >> 14) & 16383) + 1;
        }
        type = "image/webp";
    }
    return w > 0 && h > 0 && w <= 2048 && h <= 2048 ? type : NULL;
}
static Result avatar_write(App *a, const Identity *u, json_object *j, int remove) {
    if (remove) {
        sqlite3_stmt *s = stmt(a, "DELETE FROM avatars WHERE user_id=?");
        if (!s)
            return dberr(a);
        sqlite3_bind_int(s, 1, u->id);
        return run(s) ? ok() : dberr(a);
    }
    const char *base64 = str(j, "base64", 2800000);
    if (!base64)
        return fail(400, "invalid_image", "בחרו תמונת JPG, PNG או WebP עד 2MB ו-2048 פיקסלים.");
    size_t cap = strlen(base64) / 4 * 3 + 3, size = 0;
    unsigned char *bytes = malloc(cap);
    if (!bytes)
        return fail(503, "busy", "נסו שוב.");
    int valid = sodium_base642bin(bytes, cap, base64, strlen(base64), NULL, &size, NULL,
                                  sodium_base64_VARIANT_ORIGINAL) == 0 &&
                size <= 2097152;
    const char *type = valid ? raster_type(bytes, size) : NULL;
    if (!type) {
        free(bytes);
        return fail(400, "invalid_image", "בחרו תמונת JPG, PNG או WebP עד 2MB ו-2048 פיקסלים.");
    }
    sqlite3_stmt *s =
        stmt(a, "INSERT INTO avatars(user_id,type,data) VALUES(?,?,?) ON CONFLICT(user_id) DO "
                "UPDATE SET type=excluded.type,data=excluded.data");
    if (!s) {
        free(bytes);
        return dberr(a);
    }
    sqlite3_bind_int(s, 1, u->id);
    bind_text(s, 2, type);
    sqlite3_bind_blob(s, 3, bytes, (int)size, SQLITE_TRANSIENT);
    int good = run(s);
    free(bytes);
    return good ? ok() : dberr(a);
}
static Result avatar_get(App *a, struct MHD_Connection *c) {
    int id = query_id(c);
    sqlite3_stmt *s = stmt(a, "SELECT type,data FROM avatars WHERE user_id=?");
    if (!s)
        return dberr(a);
    sqlite3_bind_int(s, 1, id);
    int rc = sqlite3_step(s);
    if (rc != SQLITE_ROW) {
        sqlite3_finalize(s);
        return fail(404, "not_found", "אין תמונה.");
    }
    const char *mime = col(s, 0);
    const char *type = !strcmp(mime, "image/png")    ? "image/png"
                       : !strcmp(mime, "image/jpeg") ? "image/jpeg"
                       : !strcmp(mime, "image/webp") ? "image/webp"
                                                     : NULL;
    int n = sqlite3_column_bytes(s, 1);
    if (!type || n < 1 || n > 2097152) {
        sqlite3_finalize(s);
        return dberr(a);
    }
    char *buf = malloc((size_t)n);
    if (!buf) {
        sqlite3_finalize(s);
        return fail(503, "busy", "נסו שוב.");
    }
    memcpy(buf, sqlite3_column_blob(s, 1), (size_t)n);
    sqlite3_finalize(s);
    Result r = {0};
    r.status = 200;
    r.body = buf;
    r.body_size = (size_t)n;
    r.content_type = type;
    return r;
}

/* Every mutation reaches the same authenticated dispatch. The worker-only
 * endpoints remain inaccessible to a manager even when called directly. */
static Result dispatch(App *a, struct MHD_Connection *c, const char *url, const char *method,
                       json_object *j) {
    int get = !strcmp(method, "GET"), post = !strcmp(method, "POST"),
        del = !strcmp(method, "DELETE");
    int patch = !strcmp(method, "PATCH");
    if (!get && !post && !del && !patch)
        return fail(405, "method_not_allowed", "Method not allowed");
    if (!get) {
        const char *origin = MHD_lookup_connection_value(c, MHD_HEADER_KIND, "Origin");
        if (!origin || strcmp(origin, a->origin))
            return fail(403, "invalid_origin", "Request origin is not allowed");
    }
    if (!strcmp(url, "/api/login"))
        return post ? login(a, c, j) : fail(405, "method_not_allowed", "POST required");
    if (!strcmp(url, "/api/signup/worker") || !strcmp(url, "/api/signup/manager")) {
        const char *role = !strcmp(url, "/api/signup/manager") ? "admin" : "member";
        if (get)
            return signup_status(a, role);
        if (post)
            return signup_submit(a, c, j, role);
        return fail(405, "method_not_allowed", "GET or POST required");
    }
    Identity u;
    identity(a, c, &u);
    if (get && !strcmp(url, "/api/session"))
        return session(&u);
    if (!u.id)
        return fail(401, "unauthorized", "יש להתחבר למערכת.");
    if (!get) {
        const char *csrf = MHD_lookup_connection_value(c, MHD_HEADER_KIND, "X-CSRF-Token");
        if (!csrf || strlen(csrf) != 64 || sodium_memcmp(csrf, u.csrf, 64))
            return fail(403, "invalid_csrf", "רעננו את הדף ונסו שוב.");
    }
    if (post && !strcmp(url, "/api/logout")) {
        sqlite3_stmt *s = stmt(a, "DELETE FROM sessions WHERE hash=?");
        if (!s)
            return dberr(a);
        bind_text(s, 1, u.session_hash);
        if (!run(s))
            return dberr(a);
        Result r = ok();
        snprintf(r.cookie, sizeof r.cookie,
                 "shift_session=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0%s",
                 a->secure ? "; Secure" : "");
        return r;
    }
    if (post && !strcmp(url, "/api/password"))
        return password(a, c, &u, j);
    if (u.must_change)
        return fail(403, "password_change_required", "יש להחליף את הסיסמה הזמנית.");
    if (post && !strcmp(url, "/api/wolfe"))
        return wolfe_command(a, &u, j);
    if (get && !strcmp(url, "/api/week"))
        return week_view(a, &u, c);
    if (!strcmp(url, "/api/avatar")) {
        if (get)
            return avatar_get(a, c);
        if (post || del)
            return avatar_write(a, &u, j, del);
    }
    if (patch && !strcmp(url, "/api/profile"))
        return profile_update(a, &u, j);
    if (get && !strcmp(url, "/api/today"))
        return today_view(a);
    if (!strcmp(url, "/api/receipts")) {
        if (get)
            return receipts_view(a, &u, c);
        if (post)
            return receipt_write(a, &u, j);
    }
    if (get && !strcmp(url, "/api/profile"))
        return profile_get(a, &u);
    if (!strcmp(url, "/api/shifts")) {
        if (post || del)
            return shift_change(a, &u, j, del);
        return fail(405, "method_not_allowed", "POST or DELETE required");
    }
    if (!strcmp(url, "/api/desks") && get)
        return desk_view(a, &u, c);
    if (!strcmp(url, "/api/desk-reservations") && (post || patch || del))
        return desk_reserve(a, &u, j, patch, del);
    if (!strncmp(url, "/api/admin/", 11)) {
        if (!u.admin)
            return fail(403, "forbidden", "הפעולה זמינה למנהל/ת בלבד.");
        if (get && !strcmp(url, "/api/admin/signups"))
            return signup_list(a);
        if (post && !strcmp(url, "/api/admin/signup-settings"))
            return signup_setting(a, j);
        if (post && !strcmp(url, "/api/admin/signup-review"))
            return signup_review(a, &u, j);
        if (post && !strcmp(url, "/api/admin/signup-process"))
            return signup_process(a, &u, j);
        if (post && !strcmp(url, "/api/admin/report"))
            return workday_report(a, j);
        if (!strcmp(url, "/api/admin/events")) {
            if (get)
                return array_result(
                    "events",
                    stmt(a, "SELECT id,date,title,kind,description FROM events WHERE "
                            "date>=date('now','localtime','start of month') ORDER BY date,id"),
                    a);
            if (post || patch || del)
                return event_write(a, j, patch, del);
        }
        if (post && !strncmp(url, "/api/admin/accounts/", 20)) {
            if (!strcmp(url + 20, "reset-password") && !hash_budget(a, c, 1.0))
                return fail(429, "busy", "נסו שוב בעוד רגע.");
            return account_action(a, &u, j, url + 20);
        }
        if (!strcmp(url, "/api/admin/desks")) {
            if (get)
                return array_result("desks",
                                    stmt(a, "SELECT id,name,active FROM desks ORDER BY id"), a);
            if (post || patch || del)
                return desk_manage(a, j, patch, del);
        }
        if (!strcmp(url, "/api/admin/users")) {
            if (get)
                return array_result(
                    "users",
                    stmt(a,
                         "SELECT id,login,name,first_name,last_name,gender,birth_date,role,active "
                         "FROM users ORDER BY active DESC,id"),
                    a);
            if (post) {
                if (!hash_budget(a, c, 1.0))
                    return fail(429, "busy", "נסו שוב בעוד רגע.");
                return create_user(a, j);
            }
        }
        if (get && !strcmp(url, "/api/admin/closures"))
            return array_result(
                "closures",
                stmt(a, "SELECT date,reason FROM blocked_dates WHERE "
                        "date>=date('now','localtime','start of month') ORDER BY date"),
                a);
        if (!strcmp(url, "/api/admin/closures") && (post || del))
            return close_date(a, &u, j, del);
    }
    return fail(404, "not_found", "Endpoint not found");
}
Result api(App *a, struct MHD_Connection *c, const char *url, const char *method, json_object *j) {
    Result r = dispatch(a, c, url, method, j);
    /* Preserve the fork's 30-day inactivity policy in DB and browser together.
     * A password rotation or logout already supplies its own replacement cookie. */
    if (!*r.cookie) {
        Identity u;
        if (identity(a, c, &u)) {
            sqlite3_stmt *s = stmt(a, "UPDATE sessions SET expires=? WHERE hash=?");
            if (s) {
                sqlite3_bind_int64(s, 1, (sqlite3_int64)time(NULL) + 2592000);
                bind_text(s, 2, u.session_hash);
                if (run(s)) {
                    const char *token =
                        MHD_lookup_connection_value(c, MHD_COOKIE_KIND, "shift_session");
                    snprintf(
                        r.cookie, sizeof r.cookie,
                        "shift_session=%s; Path=/; HttpOnly; SameSite=Strict; Max-Age=2592000%s",
                        token, a->secure ? "; Secure" : "");
                }
            }
        }
    }
    return r;
}
