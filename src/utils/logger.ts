import { getLoggerBuilder } from '@nextcloud/logger'

const logger = getLoggerBuilder()
    .setApp('sendentsynchroniser')
    .detectUser()
    .build()

export default logger;