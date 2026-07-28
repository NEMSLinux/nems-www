local apache2 = require("apache2")

-- Core SSO Engine: Communicates internally with PHP
local function nems_authorize(r, required_role)
    -- Set internal header for sso_check.php
    r.headers_in["X-NEMS-Required-Role"] = required_role

    -- Execute internal subrequest to PHP checker
    local res = r:subreq_lookup_uri("/inc/sso_check.php")
    local status = res:run()

    -- 200 OK: Valid session & sufficient privileges
    if status == 200 then
        local user = res.headers_out["X-Remote-User"]
        if user and user ~= "" then
            r.user = user
            r.req_headers["REMOTE_USER"] = user
            return apache2.OK
        end
    end

    -- 403 Forbidden: Authenticated, but role level is too low
    if status == 403 then
        r.headers_out["Location"] = "/403.php"
        return 302
    end

    -- 401 Unauthorized or Invalid: Send to login page
    r.headers_out["Location"] = "/login/"
    return 302
end

-- =====================
-- Exported Apache Hooks
-- =====================

function nems_require_viewer(r)
    return nems_authorize(r, "viewer")
end

function nems_require_user(r)
    return nems_authorize(r, "user")
end

function nems_require_operator(r)
    return nems_authorize(r, "operator")
end

function nems_require_admin(r)
    return nems_authorize(r, "admin")
end

function nems_require_superadmin(r)
    return nems_authorize(r, "superadmin")
end
