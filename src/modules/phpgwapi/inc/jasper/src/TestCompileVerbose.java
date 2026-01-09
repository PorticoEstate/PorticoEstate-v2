import net.sf.jasperreports.engine.JasperCompileManager;
import java.io.File;

public class TestCompileVerbose {
public static void main(String[] args) {
if (args.length < 1) {
System.out.println("Usage: java TestCompileVerbose <jrxml_file>");
System.exit(1);
}

try {
File file = new File(args[0]);
System.out.println("File: " + file.getAbsolutePath());
System.out.println("Exists: " + file.exists());
System.out.println("Readable: " + file.canRead());
System.out.println("Size: " + file.length() + " bytes");

String jasperFile = JasperCompileManager.compileReportToFile(args[0]);
System.out.println("SUCCESS: Compiled to " + jasperFile);
System.exit(0);
} catch (Exception e) {
System.err.println("ERROR: " + e.getClass().getName() + ": " + e.getMessage());
Throwable cause = e.getCause();
if (cause != null) {
System.err.println("CAUSE: " + cause.getClass().getName() + ": " + cause.getMessage());
cause.printStackTrace(System.err);
}
e.printStackTrace(System.err);
System.exit(1);
}
}
}
