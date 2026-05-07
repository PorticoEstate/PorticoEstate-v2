import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import * as fs from 'fs';
import * as path from 'path';

export interface DbConfig {
  db_type: string;
  db_host: string;
  db_port: string;
  db_name: string;
  db_user: string;
  db_pass: string;
  domain: string;
}

export interface AppConfig {
  database: DbConfig;
  redis: {
    host: string;
    port: number;
  };
  websocket: {
    port: number;
    logEnabled: boolean;
    debugLogEnabled: boolean;
  };
  hosts: {
    nextjs: string;
    slim: string;
    websocket: string;
  };
}

@Injectable()
export class PhpConfigService implements OnModuleInit {
  private readonly logger = new Logger(PhpConfigService.name);
  private config!: AppConfig;

  onModuleInit() {
    this.config = this.loadConfig();
  }

  getConfig(): AppConfig {
    return this.config;
  }

  getDbConfig(): DbConfig {
    return this.config.database;
  }

  private loadConfig(): AppConfig {
    const dbConfig = this.parsePhpConfig();

    return {
      database: dbConfig,
      redis: {
        host: process.env.REDIS_HOST || 'portico_redis',
        port: parseInt(process.env.REDIS_PORT || '6379', 10),
      },
      websocket: {
        port: parseInt(process.env.WS_PORT || '8081', 10),
        logEnabled: process.env.WSS_LOG_ENABLED !== 'false',
        debugLogEnabled: process.env.WSS_DEBUG_LOG_ENABLED === 'true',
      },
      hosts: {
        nextjs: process.env.NEXTJS_HOST || 'portico_nextjs',
        slim: process.env.SLIM_HOST || 'portico_api',
        websocket: process.env.WEBSOCKET_HOST || 'portico_websocket',
      },
    };
  }

  private parsePhpConfig(): DbConfig {
    const configPaths = [
      '/var/www/html/config/header.inc.php',
      path.resolve(__dirname, '../../../../config/header.inc.php'),
    ];

    let fileContent: string | null = null;
    let usedPath: string | null = null;

    for (const configPath of configPaths) {
      if (fs.existsSync(configPath)) {
        fileContent = fs.readFileSync(configPath, 'utf-8');
        usedPath = configPath;
        break;
      }
    }

    if (!fileContent || !usedPath) {
      this.logger.warn(
        `PHP config not found at any of: ${configPaths.join(', ')}. Using environment variables.`,
      );
      return this.configFromEnv();
    }

    this.logger.log(`Loading database configuration from ${usedPath}`);
    return this.extractDbConfig(fileContent);
  }

  private extractDbConfig(content: string): DbConfig {
    // Parse the $phpgw_domain array from header.inc.php
    // Supports two formats:
    //   1. $phpgw_domain['default'] = array( 'db_host' => 'localhost', ... );
    //   2. $phpgw_domain['default']['db_host'] = 'localhost';
    const domainEntries = new Map<string, Map<string, string>>();

    // Format 1: array() block assignment (most common)
    // Match: $phpgw_domain['default'] = array( ... );
    const blockRegex =
      /\$phpgw_domain\s*\[\s*['"]([^'"]+)['"]\s*\]\s*=\s*array\s*\(([\s\S]*?)\)\s*;/g;
    let blockMatch: RegExpExecArray | null;

    while ((blockMatch = blockRegex.exec(content)) !== null) {
      const [, domain, body] = blockMatch;
      if (!domainEntries.has(domain)) {
        domainEntries.set(domain, new Map());
      }
      const domainMap = domainEntries.get(domain)!;

      // Extract key => value pairs from the array body
      const kvRegex = /['"](\w+)['"]\s*=>\s*['"]([^'"]*)['"]/g;
      let kvMatch: RegExpExecArray | null;
      while ((kvMatch = kvRegex.exec(body)) !== null) {
        domainMap.set(kvMatch[1], kvMatch[2]);
      }
    }

    // Format 2: individual assignments (fallback)
    const entryRegex =
      /\$phpgw_domain\s*\[\s*['"]([^'"]+)['"]\s*\]\s*\[\s*['"]([^'"]+)['"]\s*\]\s*=\s*['"]([^'"]*)['"]/g;
    let match: RegExpExecArray | null;

    while ((match = entryRegex.exec(content)) !== null) {
      const [, domain, key, value] = match;
      if (!domainEntries.has(domain)) {
        domainEntries.set(domain, new Map());
      }
      domainEntries.get(domain)!.set(key, value);
    }

    // Pick 'default' domain or first available
    let domainName = 'default';
    let domainConfig = domainEntries.get('default');

    if (!domainConfig && domainEntries.size > 0) {
      domainName = domainEntries.keys().next().value!;
      domainConfig = domainEntries.get(domainName);
      this.logger.log(`Using first domain: ${domainName}`);
    }

    if (!domainConfig || domainConfig.size === 0) {
      this.logger.warn(
        'Could not parse phpgw_domain from config file. Using environment variables.',
      );
      return this.configFromEnv();
    }

    let dbHost = domainConfig.get('db_host') || 'database';
    if (dbHost === 'localhost') {
      this.logger.log(
        "Changed db_host from 'localhost' to 'database' for Docker compatibility",
      );
      dbHost = 'database';
    }

    const config: DbConfig = {
      db_type: domainConfig.get('db_type') || 'pgsql',
      db_host: dbHost,
      db_port: domainConfig.get('db_port') || '5432',
      db_name: domainConfig.get('db_name') || 'portico',
      db_user: domainConfig.get('db_user') || 'portico',
      db_pass: domainConfig.get('db_pass') || 'portico',
      domain: domainName,
    };

    this.logger.log(
      `Database config: type=${config.db_type}, host=${config.db_host}, name=${config.db_name}, domain=${config.domain}`,
    );

    return config;
  }

  private configFromEnv(): DbConfig {
    return {
      db_type: process.env.DB_TYPE || 'pgsql',
      db_host: process.env.DB_HOST || 'database',
      db_port: process.env.DB_PORT || '5432',
      db_name: process.env.DB_NAME || 'portico',
      db_user: process.env.DB_USER || 'portico',
      db_pass: process.env.DB_PASS || 'portico',
      domain: process.env.DOMAIN || 'default',
    };
  }
}
