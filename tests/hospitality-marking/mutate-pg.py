#!/usr/bin/env python3
"""
Mutation check for the three runners of tests/hospitality-marking (#1424):

  census-pg.php              has_hospitality equals the checkout's predicate on every resource, through the three endpoints (R*, N*, M*, D*, C*)
  cache-invalidation-pg.php  the six admin hospitality writes drop the caches, and a throwing cache never fails the write (H*)
  cache-service.php          what CacheService::invalidateHospitality() really sends (S*)

  python3 tests/hospitality-marking/mutate-pg.py [--container pe-api] [--config /var/www/html/config/header.inc.php] [--rows] [R1 H3 S2 ...]

A green runner proves nothing until it is shown to go RED. This driver breaks ONE thing at a time in a copy of the file that holds it, runs the
runner that is meant to catch it against the copy (REPOSITORY_FILE, RESOURCE_REPOSITORY_FILE, RESOURCE_MODEL_FILE, DATASTORE_FILE,
RESOURCE_CONTROLLER_FILE, CONTROLLER_FILE, CACHE_SERVICE_FILE) inside the container, and requires:
  1. the mutation text was really found exactly once (a mutation that did not apply would «survive»),
  2. the runner exits 1 (RED), and not 2 (could not measure) or a crash,
  3. the row(s) that are MEANT to catch this mutant are among the failures.
Each runner's baseline (the unmodified files) must be GREEN first. Nothing in the repository is touched; the container's own /tmp holds a fresh
export (mktemp, nothing is deleted) and the copies. The runners read the database, or write inside a transaction that is rolled back.
Exit 0 = every mutant killed on its own row, 1 = anything else.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
FILES = {
    "repository": ("src/modules/booking/repositories/HospitalityRepository.php", "REPOSITORY_FILE"),
    "resource_repository": ("src/modules/bookingfrontend/repositories/ResourceRepository.php", "RESOURCE_REPOSITORY_FILE"),
    "model": ("src/modules/bookingfrontend/models/Resource.php", "RESOURCE_MODEL_FILE"),
    "datastore": ("src/modules/bookingfrontend/controllers/DataStore.php", "DATASTORE_FILE"),
    "resource_controller": ("src/modules/bookingfrontend/controllers/ResourceController.php", "RESOURCE_CONTROLLER_FILE"),
    "controller": ("src/modules/booking/controllers/HospitalityController.php", "CONTROLLER_FILE"),
    "cache_service": ("src/modules/bookingfrontend/services/CacheService.php", "CACHE_SERVICE_FILE"),
}
RUNNERS = {  # runner file -> whether it takes --config (it reads the database)
    "census-pg.php": True,
    "cache-invalidation-pg.php": True,
    "cache-service.php": False,
}
PHP = shutil.which("php") or "php"
T = "\t"

SET_ROW = "set/getResourceIdsWithHospitality() holds a resource exactly when"
# The SQL of getResourceIdsWithHospitality, as the repository spells it (two selects, UNION).
OWN_SELECT_TAIL = "                 WHERE h.active = 1\n                   AND h.allow_on_site_hospitality = 1\n                UNION\n"
LOCATION_SELECT = (
    "                UNION\n"
    "                SELECT rl.resource_id AS resource_id\n"
    "                  FROM bb_hospitality_remote_location rl\n"
    "                  JOIN bb_hospitality h ON h.id = rl.hospitality_id\n"
    "                 WHERE h.active = 1\n"
    "                   AND rl.active = 1\n"
    "                   AND (h.allow_on_site_hospitality = 1 OR h.remote_serving_enabled = 1)\n"
)
HOOK = T * 3 + "$this->invalidateHospitalityMarking();\n"
CATCH = T * 2 + "} catch (\\Throwable $e) {\n"

# (id, description, old text, new text, row-name fragments that MUST fail, file kind, runner)
MUTANTS = [
    # ── census-pg.php: the SQL of the set form ──
    ("R1", "the remote arm is dropped: no delivery location or connected room is offered, only own resources",
     LOCATION_SELECT, "", [SET_ROW], "repository", "census-pg.php"),
    ("R2", "the delivery arm is dropped: a delivery-only hospitality's locations are not offered (on-site service only)",
     "AND (h.allow_on_site_hospitality = 1 OR h.remote_serving_enabled = 1)", "AND h.allow_on_site_hospitality = 1",
     [SET_ROW, "set/every member of an offering class is in it"], "repository", "census-pg.php"),
    ("R3", "h.active is not judged on the own-resource arm (an inactive hospitality's own resource is offered)",
     OWN_SELECT_TAIL, "                 WHERE h.allow_on_site_hospitality = 1\n                UNION\n",
     [SET_ROW], "repository", "census-pg.php"),
    ("R4", "h.active is not judged on the location arm (an inactive hospitality's locations are offered)",
     "                 WHERE h.active = 1\n                   AND rl.active = 1\n", "                 WHERE rl.active = 1\n",
     [SET_ROW], "repository", "census-pg.php"),
    ("R5", "rl.active is not judged (an inactive delivery location is offered)",
     "                   AND rl.active = 1\n                   AND (h.allow_on_site_hospitality", "                   AND (h.allow_on_site_hospitality",
     [SET_ROW], "repository", "census-pg.php"),
    ("R6", "the own-resource arm ignores allow_on_site_hospitality (the own resource of a delivery-only hospitality is offered)",
     "                   AND h.allow_on_site_hospitality = 1\n                UNION\n", "                UNION\n",
     [SET_ROW], "repository", "census-pg.php"),
    # ── census-pg.php: the wiring ──
    ("N1", "N+1: one getActiveByResourceIds() per resource instead of the one set query (same answers, many statements)",
     "        $offered = array_fill_keys((new HospitalityRepository())->getResourceIdsWithHospitality(), true);\n"
     "        foreach ($resources as $resource) {\n"
     "            $resource->has_hospitality = isset($offered[(int)$resource->id]);\n"
     "        }",
     "        $repository = new HospitalityRepository();\n"
     "        foreach ($resources as $resource) {\n"
     "            $resource->has_hospitality = !empty($repository->getActiveByResourceIds([(int)$resource->id]));\n"
     "        }",
     ["search-data/ONE statement on bb_hospitality", "building-resources/full: the most statements on bb_hospitality any ONE request issues"],
     "resource_repository", "census-pg.php"),
    ("N2", "every resource is flagged true",
     "$resource->has_hospitality = isset($offered[(int)$resource->id]);", "$resource->has_hospitality = true;",
     ["search-data/every resource of the payload carries", "resource/GET /resources/{id} carries"], "resource_repository", "census-pg.php"),
    ("N3", "createAndSerialize ignores withHospitality (the building's resource list carries no flag)",
     "        if ($withHospitality) {\n            $this->setHasHospitality($resources);\n        }\n", "",
     ["building-resources/full: every resource", "building-resources/short: every resource"], "resource_repository", "census-pg.php"),
    ("M1", "has_hospitality is not @Short (the search data and the short list lose it)",
     "     * @Expose\n     * @Short\n     * @OA\\Property(description=\"Whether food and drink can be ordered",
     "     * @Expose\n     * @OA\\Property(description=\"Whether food and drink can be ordered",
     ["search-data/every resource of the payload carries", "building-resources/short: every resource"], "model", "census-pg.php"),
    ("M2", "has_hospitality is not @Expose (it never reaches any payload)",
     "     * @Expose\n     * @Short\n     * @OA\\Property(description=\"Whether food and drink can be ordered",
     "     * @Short\n     * @OA\\Property(description=\"Whether food and drink can be ordered",
     ["search-data/every resource of the payload carries", "resource/GET /resources/{id} carries", "building-resources/full: every resource"],
     "model", "census-pg.php"),
    ("D1", "SearchDataAllOptimised does not set the flag",
     "\t\t\t$this->resourceRepository->setHasHospitality($resourceEntities);\n", "",
     ["search-data/every resource of the payload carries"], "datastore", "census-pg.php"),
    ("C1", "GET /resources/{id} does not set the flag",
     "            $this->resourceRepository->setHasHospitality([$resource]);\n", "",
     ["resource/GET /resources/{id} carries"], "resource_controller", "census-pg.php"),
    ("C2", "GET /buildings/{id}/resources does not ask for the flag",
     "createAndSerialize($results, $this->getUserRoles(), $short, true)", "createAndSerialize($results, $this->getUserRoles(), $short)",
     ["building-resources/full: every resource", "building-resources/short: every resource"], "resource_controller", "census-pg.php"),
    # ── cache-invalidation-pg.php: the six writes, and the guard around the call ──
    ("H1", "store() does not drop the caches",
     T * 3 + "$model = new Hospitality($row);\n" + HOOK, T * 3 + "$model = new Hospitality($row);\n",
     ["store/POST a hospitality on an offered resource"], "controller", "cache-invalidation-pg.php"),
    ("H2", "update() does not drop the caches",
     HOOK + "\n" + T * 3 + "// Broadcast update via WebSocket.", "\n" + T * 3 + "// Broadcast update via WebSocket.",
     ["update/PUT a rename"], "controller", "cache-invalidation-pg.php"),
    ("H3", "destroy() does not drop the caches",
     T * 3 + "$this->repository->delete($id);\n" + HOOK, T * 3 + "$this->repository->delete($id);\n",
     ["destroy/DELETE the fixture"], "controller", "cache-invalidation-pg.php"),
    ("H4", "addRemoteLocation() does not drop the caches",
     T * 3 + "$this->repository->addRemoteLocation($id, (int)$body['resource_id']);\n" + HOOK,
     T * 3 + "$this->repository->addRemoteLocation($id, (int)$body['resource_id']);\n",
     ["add-location/POST a delivery location"], "controller", "cache-invalidation-pg.php"),
    ("H5", "removeRemoteLocation() does not drop the caches",
     T * 3 + "$this->repository->removeRemoteLocation($id, $resourceId);\n" + HOOK, T * 3 + "$this->repository->removeRemoteLocation($id, $resourceId);\n",
     ["remove-location/DELETE the location"], "controller", "cache-invalidation-pg.php"),
    ("H6", "toggleRemoteLocation() does not drop the caches",
     T * 3 + "$this->repository->toggleRemoteLocation($id, $resourceId, (bool)$body['active']);\n" + HOOK,
     T * 3 + "$this->repository->toggleRemoteLocation($id, $resourceId, (bool)$body['active']);\n",
     ["toggle-location/PATCH active=false", "toggle-location/PATCH active=true"], "controller", "cache-invalidation-pg.php"),
    ("H7", "an Error from the cache layer reaches the write (the guard catches Exception, not Throwable)",
     CATCH, T * 2 + "} catch (\\Exception $e) {\n",
     ["resilience/the cache throws an Error"], "controller", "cache-invalidation-pg.php"),
    ("H8", "an Exception from the cache layer reaches the write (the guard catches only TypeError)",
     CATCH, T * 2 + "} catch (\\TypeError $e) {\n",
     ["resilience/the cache throws an Exception"], "controller", "cache-invalidation-pg.php"),
    ("H9", "update() drops the caches twice",
     HOOK + "\n" + T * 3 + "// Broadcast update via WebSocket.", HOOK + HOOK + "\n" + T * 3 + "// Broadcast update via WebSocket.",
     ["update/PUT a rename"], "controller", "cache-invalidation-pg.php"),
    ("H10", "a refusal drops the caches too (a cache is dropped for a change that happened, not for one that did not)",
     T + "private function refuse(Response $response, array $refusal): Response\n" + T + "{\n",
     T + "private function refuse(Response $response, array $refusal): Response\n" + T + "{\n" + T * 2 + "$this->invalidateHospitalityMarking();\n",
     ["update/PUT a move to an inactive resource is refused", "store/POST on an inactive resource is refused"], "controller", "cache-invalidation-pg.php"),
    # ── cache-service.php: what is really sent ──
    ("S1", "the Next.js request leaves out the resources tag (the resource page would lag)",
     "'tag=search-data&tag=resources'", "'tag=search-data'",
     ["hospitality/one request to Next.js"], "cache_service", "cache-service.php"),
    ("S2", "the browsers are not told to drop the resource query",
     T * 3 + "['resource'],             // GET /resources/{id}: the resource page and the info dialog\n", "",
     ["hospitality/one broadcast"], "cache_service", "cache-service.php"),
    ("S3", "the browsers are not told to drop the building resources query",
     T * 3 + "['buildingResources']     // GET /buildings/{id}/resources: the calendar list and the application form\n", "",
     ["hospitality/one broadcast"], "cache_service", "cache-service.php"),
    ("S4", "the resource query is named with ONE id instead of by key prefix (every other resource would lag)",
     "['resource'],             // GET", "['resource', '1'],         // GET",
     ["hospitality/one broadcast"], "cache_service", "cache-service.php"),
]


def sh(*cmd, input_bytes=None):
    return subprocess.run(cmd, input=input_bytes, capture_output=True)


def main():
    args = sys.argv[1:]
    opts = {"--container": "pe-api", "--config": "/var/www/html/config/header.inc.php"}
    show_rows = "--rows" in args
    only = set()
    i = 0
    while i < len(args):
        if args[i] in opts:
            opts[args[i]] = args[i + 1]
            i += 2
        else:
            if not args[i].startswith("--"):
                only.add(args[i])
            i += 1
    unknown = only - {m[0] for m in MUTANTS}
    if unknown:
        print(f"UNKNOWN MUTANT ID(S) {sorted(unknown)}: nothing was run")
        return 1
    container, config = opts["--container"], opts["--config"]

    # a fresh export, in a directory the container makes for it (nothing is removed): this tree's booking and bookingfrontend modules
    # (without the Next.js client) and this harness, with vendor/ linked from the served tree (read only)
    made = sh("docker", "exec", container, "mktemp", "-d", "/tmp/hm-census.XXXXXX")
    workdir = made.stdout.decode().strip()
    if made.returncode != 0 or not workdir.startswith("/tmp/hm-census."):
        print("COULD NOT MAKE an export directory in the container:", made.stderr.decode()[:300])
        return 1
    sh("docker", "exec", container, "ln", "-s", "/var/www/html/vendor", f"{workdir}/vendor")
    tar = subprocess.run(["tar", "-c", "--exclude=src/modules/bookingfrontend/client", "-C", ROOT,
                          "src/modules/booking", "src/modules/bookingfrontend", "tests/hospitality-marking"], capture_output=True)
    put = sh("docker", "exec", "-i", container, "tar", "-x", "-C", workdir, input_bytes=tar.stdout)
    if tar.returncode != 0 or put.returncode != 0:
        print("COULD NOT EXPORT the tree into the container:", (tar.stderr + put.stderr).decode()[:300])
        return 1

    def run(runner, env_files):
        env = []
        for key, path in env_files.items():
            env += ["-e", f"{key}={path}"]
        cmd = ["docker", "exec", *env, "-w", workdir, container, "php", "-d", "xdebug.mode=off", f"tests/hospitality-marking/{runner}"]
        if RUNNERS[runner]:
            cmd.append(f"--config={config}")
        p = sh(*cmd)
        return p.returncode, (p.stdout + p.stderr).decode("utf-8", "replace")

    wanted = [m for m in MUTANTS if not only or m[0] in only]
    for runner in sorted({m[6] for m in wanted}):
        rc, out = run(runner, {})
        verdict = [line for line in out.strip().splitlines() if line.startswith(("GREEN", "RED", "CANNOT"))]
        print(f"baseline {runner:26} rc={rc}  {verdict[-1] if verdict else out.strip()[-200:]}")
        if rc != 0:
            print(f"BASELINE OF {runner} IS NOT GREEN - mutation results would mean nothing")
            return 1

    originals = {kind: open(os.path.join(ROOT, path), encoding="utf-8").read() for kind, (path, _) in FILES.items()}
    scratch = tempfile.mkdtemp(prefix="hm-census-")
    bad = 0
    for mid, desc, old, new, must_fail, kind, runner in wanted:
        source = originals[kind]
        count = source.count(old)
        if count != 1:
            print(f"{mid:4} NOT APPLIED ({count} matches) - {desc}")
            bad += 1
            continue
        local = os.path.join(scratch, f"{mid}.php")
        with open(local, "w", encoding="utf-8") as fh:
            fh.write(source.replace(old, new))
        lint = sh(PHP, "-l", local)
        if lint.returncode != 0:
            print(f"{mid:4} MUTANT DOES NOT PARSE - {desc}: {lint.stdout.decode().strip()}")
            bad += 1
            continue
        mutant_path = f"{workdir}/{mid}.php"
        sh("docker", "cp", local, f"{container}:{mutant_path}")
        os.unlink(local)
        rc, out = run(runner, {FILES[kind][1]: mutant_path})
        failed = [line for line in out.splitlines() if line.startswith("FAIL")]
        missing = [m for m in must_fail if not any(m in line for line in failed)]
        if rc == 0:
            print(f"{mid:4} SURVIVED (rc=0) - {desc}")
            bad += 1
        elif rc != 1:
            print(f"{mid:4} CRASH or CANNOT MEASURE (rc={rc}) - {desc}: {out.strip().splitlines()[-1][:160] if out.strip() else ''}")
            bad += 1
        elif missing:
            print(f"{mid:4} killed, but NOT on the intended row(s) {missing} - {desc}")
            bad += 1
        else:
            print(f"{mid:4} killed on {len(failed)} row(s), incl. the intended ones - {desc}")
        if show_rows:
            for line in failed:
                print("       RED  " + line[len("FAIL ["):].split("] got ", 1)[0])
    os.rmdir(scratch)
    print("ALL MUTANTS KILLED ON THEIR OWN ROW" if bad == 0 else f"{bad} MUTANT PROBLEM(S)")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
