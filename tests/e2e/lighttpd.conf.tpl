# Mirrors what the Dockerfile assembles inside the container:
#   COPY conf-available/{15-fastcgi-php,10-fastcgi}.conf -> conf-enabled/
#   echo 'dir-listing.activate = "enable"' >> lighttpd.conf
#   index-file.names = ( "index.php", ... )
#
# Anything @PLACEHOLDER@ is substituted by run_e2e.sh before lighttpd boots.
server.document-root        = "@DOCROOT@"
server.upload-dirs          = ( "@TMPDIR@/upload" )
server.errorlog             = "@TMPDIR@/error.log"
server.pid-file             = "@TMPDIR@/lighttpd.pid"
server.port                 = @PORT@
server.bind                 = "127.0.0.1"

server.modules = (
    "mod_indexfile",
    "mod_access",
    "mod_alias",
    "mod_redirect",
    "mod_fastcgi",
    "mod_accesslog",
    "mod_dirlisting",
    "mod_staticfile",
)

accesslog.filename          = "@TMPDIR@/access.log"

index-file.names            = ( "index.php", "index.html" )
mimetype.assign             = (
    ".css"  => "text/css",
    ".js"   => "application/javascript",
    ".html" => "text/html",
    ".jpg"  => "image/jpeg",
    ".jpeg" => "image/jpeg",
    ".png"  => "image/png",
    ".pdf"  => "application/pdf",
    ".json" => "application/json",
    ".txt"  => "text/plain",
    ""      => "application/octet-stream",
)

dir-listing.activate        = "enable"

fastcgi.server = ( ".php" =>
    ((
        "bin-path"     => "@PHPCGI@",
        "socket"       => "@TMPDIR@/php.sock",
        "max-procs"    => 1,
        "broken-scriptfilename" => "enable",
    ))
)
