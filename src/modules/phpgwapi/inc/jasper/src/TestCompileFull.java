import net.sf.jasperreports.engine.JasperCompileManager;
import java.io.File;

public class TestCompileFull {
public static void main(String[] args) throws Exception {
if (args.length < 1) {
System.out.println("Usage: java TestCompileFull <jrxml_file>");
System.exit(1);
}

File file = new File(args[0]);
System.out.println("Compiling: " + file.getAbsolutePath());
String result = JasperCompileManager.compileReportToFile(args[0]);
System.out.println("SUCCESS: " + result);
}
}
