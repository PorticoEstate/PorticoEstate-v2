#!/usr/bin/env python3
"""
Mutation check for tests/hospitality-direct-booking-guard/main-resource-offer-pg.php (task 47994, C2).

  python3 tests/hospitality-direct-booking-guard/mutate-offer-pg.py [--container pe-api] [--dir /tmp/hd-offer-pg]
          [--config /var/www/html/config/header.inc.php] [--rows] [S1 S5 ...]

run.php cannot see the SQL of HospitalityRepository::mainResourceOffer (it answers from a double), so its mutants cannot break it. This
driver breaks ONE thing at a time in a copy of HospitalityRepository.php (the SQL and the way its answer is read) or of
HospitalityController.php, runs the PostgreSQL runner against the copy (REPOSITORY_FILE / CONTROLLER_FILE) inside the container, and requires:
  1. the mutation text was really found exactly once,
  2. the runner exits 1 (RED), and not 2 (could not measure) or a crash,
  3. the row(s) that are MEANT to catch this mutant are among the failures.
The baseline (the unmodified files) must be GREEN first. Nothing in the repository is touched; the container's own /tmp holds the export
and the copies, and the runner rolls its database work back. Exit 0 = every mutant killed on its own row.
"""
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
REPOSITORY = os.path.join(ROOT, "src/modules/booking/repositories/HospitalityRepository.php")
CONTROLLER = os.path.join(ROOT, "src/modules/booking/controllers/HospitalityController.php")
T = "\t"

# (id, description, old text, new text, row-name fragments that MUST fail, "repository" | "controller")
MUTANTS = [
    ("S1", "active is not read (every resource counts as active)",
     "'active' => (int)$row['active'] === 1,", "'active' => true,",
     ["(inactive)"], "repository"),
    ("S2", "active is read the wrong way round",
     "'active' => (int)$row['active'] === 1,", "'active' => (int)$row['active'] === 0,",
     ["says «offered» for exactly the resources the picker reaches"], "repository"),
    ("S3", "the building's active flag is not judged (a building that is switched off still counts)",
     "WHERE br.resource_id = r.id AND b.active = 1", "WHERE br.resource_id = r.id",
     ["(only inactive buildings)"], "repository"),
    ("S4", "the building's active flag is inverted (only a switched-off building counts)",
     "WHERE br.resource_id = r.id AND b.active = 1", "WHERE br.resource_id = r.id AND b.active = 0",
     ["says «offered» for exactly the resources the picker reaches"], "repository"),
    ("S5", "a resource in no building counts as being in an active building",
     "THEN 1 ELSE 0 END AS in_active_building", "THEN 1 ELSE 1 END AS in_active_building",
     ["(no building)"], "repository"),
    ("S6", "the building join is on the wrong key (a resource id is compared with a building id)",
     "JOIN bb_building b ON b.id = br.building_id\n                         WHERE br.resource_id = r.id AND b.active = 1",
     "JOIN bb_building b ON b.id = br.resource_id\n                         WHERE br.resource_id = r.id AND b.active = 1",
     ["says «offered» for exactly the resources the picker reaches"], "repository"),
    ("S7", "a resource that does not exist is not answered with null",
     "        if ($row === false) {\n            return null;\n        }\n        return [\n            'active'", "        $row = $row ?: ['active' => 1, 'in_active_building' => 1];\n        return [\n            'active'",
     ["answers null for a resource that does not exist"], "repository"),
    ("C1", "the controller does not ask the picker rule in update() (the old behaviour)",
     T*3 + "// A move must go to a resource the admin's picker offers (task 47994).\n"
       + T*3 + "if ($mainResourceChanged) {\n" + T*4 + "$refusal = $this->mainResourceNotOfferedRefusal($body['resource_id']);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n", "",
     ["pg/update a move to resource"], "controller"),
    ("C2", "the controller does not ask the picker rule in store()",
     T*3 + "// The main resource must be one the admin's picker offers (task 47994).\n"
       + T*3 + "$refusal = $this->mainResourceNotOfferedRefusal($body['resource_id']);\n"
       + T*3 + "if ($refusal !== null) {\n" + T*4 + "return $this->refuse($response, $refusal);\n" + T*3 + "}\n", "",
     ["pg/store resource"], "controller"),
    ("C3", "the controller judges the unchanged key too (a main that has gone inactive can no longer be renamed)",
     T*3 + "if ($mainResourceChanged) {\n" + T*4 + "$refusal = $this->mainResourceNotOfferedRefusal(",
     T*3 + "if (array_key_exists('resource_id', $body)) {\n" + T*4 + "$refusal = $this->mainResourceNotOfferedRefusal(",
     ["pg/update the UNCHANGED key"], "controller"),
]


def sh(*cmd, input_bytes=None):
    return subprocess.run(cmd, input=input_bytes, capture_output=True)


def main():
    args = sys.argv[1:]
    opts = {"--container": "pe-api", "--dir": "/tmp/hd-offer-pg", "--config": "/var/www/html/config/header.inc.php"}
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
    container, workdir, config = opts["--container"], opts["--dir"], opts["--config"]

    # the export: this tree's booking module and this harness directory, with vendor/ linked from the served tree (read only)
    sh("docker", "exec", container, "sh", "-c", f"rm -rf {workdir} && mkdir -p {workdir} && ln -s /var/www/html/vendor {workdir}/vendor")
    tar = subprocess.run(["tar", "-c", "-C", ROOT, "src/modules/booking", "tests/hospitality-direct-booking-guard"], capture_output=True)
    put = sh("docker", "exec", "-i", container, "tar", "-x", "-C", workdir, input_bytes=tar.stdout)
    if tar.returncode != 0 or put.returncode != 0:
        print("COULD NOT EXPORT the tree into the container:", (tar.stderr + put.stderr).decode()[:300])
        return 1

    def run(env_files):
        env = []
        for key, path in env_files.items():
            env += ["-e", f"{key}={path}"]
        p = sh("docker", "exec", *env, "-w", workdir, container, "php", "-d", "xdebug.mode=off",
               "tests/hospitality-direct-booking-guard/main-resource-offer-pg.php", f"--config={config}")
        return p.returncode, (p.stdout + p.stderr).decode("utf-8", "replace")

    rc, out = run({})
    print(f"baseline rc={rc}  {out.strip().splitlines()[-1] if out.strip() else ''}")
    if rc != 0:
        print("BASELINE IS NOT GREEN - mutation results would mean nothing")
        return 1

    originals = {"repository": open(REPOSITORY, encoding="utf-8").read(), "controller": open(CONTROLLER, encoding="utf-8").read()}
    env_key = {"repository": "REPOSITORY_FILE", "controller": "CONTROLLER_FILE"}
    bad = 0
    for mid, desc, old, new, must_fail, kind in MUTANTS:
        if only and mid not in only:
            continue
        source = originals[kind]
        count = source.count(old)
        if count != 1:
            print(f"{mid:4} NOT APPLIED ({count} matches) - {desc}")
            bad += 1
            continue
        mutant_path = f"{workdir}/{mid}.php"
        local = os.path.join("/tmp", f"hd-offer-pg-{mid}.php")
        with open(local, "w", encoding="utf-8") as fh:
            fh.write(source.replace(old, new))
        lint = sh("php", "-l", local)
        if lint.returncode != 0:
            print(f"{mid:4} MUTANT DOES NOT PARSE - {desc}: {lint.stdout.decode().strip()}")
            bad += 1
            continue
        sh("docker", "cp", local, f"{container}:{mutant_path}")
        os.unlink(local)
        rc, out = run({env_key[kind]: mutant_path})
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
    print("ALL MUTANTS KILLED ON THEIR OWN ROW" if bad == 0 else f"{bad} MUTANT PROBLEM(S)")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
