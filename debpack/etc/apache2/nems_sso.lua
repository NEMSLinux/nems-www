-- NEMS Linux SSO Hook for Apache
-- Robbie Ferguson

-- Standard Apache Hook Return Constants
local OK       = 0   -- Access Granted
local REDIRECT = 302 -- Temporary Redirect

-- Define role hierarchy levels (matches NEMS ULA logic and /var/www/html/inc/auth.php)
local role_hierarchy = {
    viewer     = 1,
    reporter   = 2,
    operator   = 3,
    admin      = 4,
    superadmin = 5,
}

local function nems_authorize(r, min_role)
    local cookie = r.headers_in["Cookie"]
    if not cookie then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    local sess_id = cookie:match("PHPSESSID=([^;]+)")
    if not sess_id then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    -- Look up active PHP session on disk
    local path = "/var/lib/php/sessions/sess_" .. sess_id
    local f = io.open(path, "r")
    if not f then
        f = io.open("/tmp/sess_" .. sess_id, "r")
    end

    if not f then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    local content = f:read("*all")
    f:close()

    -- Parse serialized PHP session variables
    local user = content:match('user|s:%d+:"([^"]+)"')
    local role = content:match('role|s:%d+:"([^"]+)"')
    local last = content:match('__last|i:(%d+);')
    local ip   = content:match('__ip|s:%d+:"([^"]+)"')
    local ua   = content:match('__ua|s:%d+:"([^"]+)"')

    -- Must have valid user & role stored in PHP session
    if not user or not role then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    -- Guard: Idle timeout (1800 seconds / 30 minutes)
    if last then
        local now = os.time()
        if (now - tonumber(last)) > 1800 then
            r.headers_out["Location"] = "/login/"
            return REDIRECT
        end
    end

    -- Guard: IP address match
    local req_ip = r.useragent_ip or r.remote_ip or ""
    if ip and ip ~= "" and req_ip ~= "" and ip ~= req_ip then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    -- Guard: User-Agent hijack check
    local req_ua = r.headers_in["User-Agent"] or ""
    if ua and ua ~= "" and req_ua ~= "" and ua ~= req_ua then
        r.headers_out["Location"] = "/login/"
        return REDIRECT
    end

    -- Authorization: Check role hierarchy level
    local user_level = role_hierarchy[role:lower()] or 0
    local required_level = role_hierarchy[min_role:lower()] or 999

    if user_level < required_level then
        -- User is logged in, but role level is too low
        r.headers_out["Location"] = "/403.php"
        return REDIRECT
    end

    -- Access Granted: Inject REMOTE_USER for backend applications (Adagios, NConf, etc.)
    r.user = user
    r.headers_in["REMOTE_USER"] = user
    r.headers_in["REMOTE-USER"] = user
    r.headers_in["HTTP_REMOTE_USER"] = user
    r.headers_in["X-Remote-User"] = user
    
    return OK
end

-- Exported role hooks for Apache configs
function nems_require_viewer(r) return nems_authorize(r, "viewer") end
function nems_require_reporter(r) return nems_authorize(r, "reporter") end
function nems_require_operator(r) return nems_authorize(r, "operator") end
function nems_require_admin(r) return nems_authorize(r, "admin") end
function nems_require_superadmin(r) return nems_authorize(r, "superadmin") end
