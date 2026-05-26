import { Module, Global } from '@nestjs/common';
import { PhpConfigService } from './php-config.service';

@Global()
@Module({
  providers: [PhpConfigService],
  exports: [PhpConfigService],
})
export class ConfigModule {}
