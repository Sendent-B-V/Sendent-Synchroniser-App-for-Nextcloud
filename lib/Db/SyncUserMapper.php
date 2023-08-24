<?php

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SyncUserMapper extends QBMapper {

	/** @var IAppConfig */
	private $appConfig;

	/** @var IDBConnection */
	public $db;

	public function __construct(IAppConfig $appConfig, IDBConnection $db) {
		parent::__construct($db, 'sndntsync_users', SyncUser::class);
		$this->appConfig = $appConfig;
		$this->db = $db;
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException if more than one result
	 *
	 * @return \OCP\AppFramework\Db\Entity
	 */
	public function findById(int $id): \OCP\AppFramework\Db\Entity {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
		   ->from('sndntsync_users')
		   ->where(
			   $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
		   );

		return $this->findEntity($qb);
	}

	/**
	 * @return \OCP\AppFramework\Db\Entity[]
	 * 
	 * @psalm-return array<\OCP\AppFramework\Db\Entity>
	 */
	public function findByUid(string $uid): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
		   ->from('sndntsync_users')
		   ->where(
			   $qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR))
		   );

		return $this->findEntities($qb);
	}

	/**
	 * @return \OCP\AppFramework\Db\Entity[]
	 *
	 * @psalm-return array<\OCP\AppFramework\Db\Entity>
	 */
	public function findAll($limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
		   ->from('sndntsync_users')
		   ->setMaxResults($limit)
		   ->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	/**
	 * 
	 * This function re-encrypts all existing users token with $secret.
	 * It shall be called when the sendent synchroniser share secret is changed.
	 * 
	 */
	public function encryptAllUserstoken($newSecret) {

		$users = $this->findAll();
		foreach($users as $user) {

			// Decrypts token
			$encryptedToken = $user->getToken();
			$c = base64_decode($encryptedToken);
			$ivlen = openssl_cipher_iv_length($cipher="AES-256-CBC");
			$iv = substr($c, 0, $ivlen);
			$hmac = substr($c, $ivlen, $sha2len=32);
			$ciphertext_raw = substr($c, $ivlen+$sha2len);
			$key = $this->appConfig->getAppValue('sharedSecret', '');
			$token = openssl_decrypt($ciphertext_raw, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);

			// Reencrypts token with new secret
			$ivlen = openssl_cipher_iv_length($cipher="AES-256-CBC");
			$iv = openssl_random_pseudo_bytes($ivlen);
			$ciphertext_raw = openssl_encrypt($token, $cipher, $newSecret, $options=OPENSSL_RAW_DATA, $iv);
			$hmac = hash_hmac('sha256', $ciphertext_raw, $newSecret, $as_binary=true);
			$encryptedToken = base64_encode( $iv.$hmac.$ciphertext_raw );
			
			// Saves reencrypted token
			$user->setToken($encryptedToken);
			$this->update($user);

		}

	}
}
