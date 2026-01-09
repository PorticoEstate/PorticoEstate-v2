

JasperReports libraries are already vendored here; no build-from-source is required for normal use.

What is bundled
- lib/: JasperReports 6.19.1 runtime and dependencies (Java 8 compatible).
- lib7/: JasperReports 7.0.3 runtime plus fonts/functions/pdf modules and updated deps (Java 11+).
- bin/: compiled helpers used by the app.

Typical use
- Point the PHP integration to the shipped jars under lib/ (or lib7/ if you target JR 7.x).
- Ensure file modes stay readable: chmod 644 phpgwapi/jasper/lib/*.jar phpgwapi/jasper/lib7/*.jar phpgwapi/jasper/bin/*.class

Updating libraries (preferred)
- Download the official binary distribution zip from Jaspersoft for the target version.
- Replace jasperreports-*.jar and jasperreports-javaflow-*.jar from that zip.
- Refresh companion jars from the distribution lib folder (Jackson, POI, SLF4J, etc.) to match the same version set.
- Keep JDBC drivers current (mysql-connector-java, postgresql). Place them alongside the other jars.
- Verify the app still targets a compatible Java runtime for the chosen JasperReports line (6.x for Java 8, 7.x for Java 11+).

Building from source (rare)
- Only if you must patch JasperReports itself: install Ant and Ivy, run ant alljars and ant retrievelibs in the JasperReports source tree, then copy the resulting dist and lib jars here. Prefer the official binaries above.
database connectors from db-system sites

https://dev.mysql.com/downloads/connector/j/

