def subject = "${env.JOB_NAME} - Build #${env.BUILD_NUMBER}"
def content = '${JELLY_SCRIPT,template="html"}'
def project_name = 'edacap-connect'
def dev_deployment_instance = 'dev.edacap.api.scio.services'
def stg_deployment_instance = 'stg.edacap.api.scio.services'
def prod_deployment_instance = ''

pipeline {
    agent any

    stages {
        stage('Fetching repository from BitBucket') {
            steps {
                echo 'Fetching repository from BitBucket'

                script {
                    checkout scm
                    sh 'git rev-parse --short HEAD > .git/commit-id'
                    commit_id = readFile('.git/commit-id').trim()
                }
            }
        }

        stage('Building & pushing docker images to DockerHub (dev)') {
            when {
                branch 'release/development'
            }
            steps {
                echo 'Building project'

                script {
                    echo 'Downloading from S3 bucket'
                    withAWS(region: 'us-east-2', credentials:'jenkins-aws') {
                        s3Download(file: '.env', bucket: 'scio-project-envs', path: "laravel/${project_name}/dev.env", force:true)
                    }

                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:dev", '.').push()
                    }
                    
                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:dev-nginx", '-f nginx/Dockerfile.dev .').push()
                    }
                }
            }
        }

        stage('Deployment stage (dev)') {
            when {
                branch 'release/development'
            }
            steps {
                echo "Deploying to ${dev_deployment_instance}"

                script {
                    sshagent(credentials: ['jenkins-ssh-key']) {withCredentials([[$class: 'UsernamePasswordMultiBinding', credentialsId:'DockerHub', usernameVariable: 'USERNAME', passwordVariable: 'PASSWORD']]) {
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} docker login -u $USERNAME -p $PASSWORD"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} sudo mkdir -p /var/lib/app/${project_name}-dev"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} sudo chown -R scio:scio /var/lib/app/${project_name}-dev"
                            sh "scp -P 1412 -o StrictHostKeyChecking=no docker-compose.dev.yml scio@${dev_deployment_instance}:/var/lib/app/${project_name}-dev"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} docker rm -f laravel-app laravel-nginx"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} docker-compose -f /var/lib/app/${project_name}-dev/docker-compose.dev.yml pull"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} docker-compose -f /var/lib/app/${project_name}-dev/docker-compose.dev.yml up -d"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${dev_deployment_instance} docker exec laravel-app chown -R www-data:www-data ."
                        }
                    }
                }
            }
        }

        stage('Building & pushing docker images to DockerHub (stg)') {
            when {
                branch 'release/staging'
            }
            steps {
                echo 'Building project'

                script {
                    echo 'Downloading from S3 bucket'
                    withAWS(region: 'us-east-2', credentials:'jenkins-aws') {
                        s3Download(file: '.env', bucket: 'scio-project-envs', path: "laravel/${project_name}/stg.env", force:true)
                    }

                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:stg", '.').push()
                    }
                    
                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:stg-nginx", '-f nginx/Dockerfile.stg .').push()
                    }
                }
            }
        }

        stage('Deployment stage (stg)') {
            when {
                branch 'release/staging'
            }
            steps {
                echo "Deploying to ${stg_deployment_instance}"

                script {
                    sshagent(credentials: ['jenkins-ssh-key']) {withCredentials([[$class: 'UsernamePasswordMultiBinding', credentialsId:'DockerHub', usernameVariable: 'USERNAME', passwordVariable: 'PASSWORD']]) {
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} docker login -u $USERNAME -p $PASSWORD"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} sudo mkdir -p /var/lib/app/${project_name}-stg"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} sudo chown -R scio:scio /var/lib/app/${project_name}-stg"
                            sh "scp -P 1412 -o StrictHostKeyChecking=no docker-compose.stg.yml scio@${stg_deployment_instance}:/var/lib/app/${project_name}-stg"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} docker rm -f laravel-app laravel-nginx"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} docker-compose -f /var/lib/app/${project_name}-stg/docker-compose.stg.yml pull"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} docker-compose -f /var/lib/app/${project_name}-stg/docker-compose.stg.yml up -d"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${stg_deployment_instance} docker exec laravel-app chown -R www-data:www-data ."
                        }
                    }
                }
            }
        }

        stage('Building & pushing docker images to DockerHub (prod)') {
            when {
                branch 'release/production'
            }
            steps {
                echo 'Building project'

                script {
                    echo 'Downloading from S3 bucket'
                    withAWS(region: 'us-east-2', credentials:'jenkins-aws') {
                        s3Download(file: '.env', bucket: 'scio-project-envs', path: "laravel/${project_name}/prod.env", force:true)
                    }

                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:prod", '.').push()
                    }
                    
                    docker.withRegistry('https://index.docker.io/v1/', 'DockerHub') {
                        docker.build("sciohub/${project_name}:prod-nginx", '-f nginx/Dockerfile.prod .').push()
                    }
                }
            }
        }

        stage('Deployment stage (prod)') {
            when {
                branch 'release/production'
            }
            steps {
                echo "Deploying to ${prod_deployment_instance}"

                script {
                    sshagent(credentials: ['jenkins-ssh-key']) {withCredentials([[$class: 'UsernamePasswordMultiBinding', credentialsId:'DockerHub', usernameVariable: 'USERNAME', passwordVariable: 'PASSWORD']]) {
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} docker login -u $USERNAME -p $PASSWORD"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} sudo mkdir -p /var/lib/app/${project_name}-prod"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} sudo chown -R scio:scio /var/lib/app/${project_name}-prod"
                            sh "scp -P 1412 -o StrictHostKeyChecking=no docker-compose.prod.yml scio@${prod_deployment_instance}:/var/lib/app/${project_name}-prod"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} docker rm -f laravel-app laravel-nginx"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} docker-compose -f /var/lib/app/${project_name}-prod/docker-compose.prod.yml pull"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} docker-compose -f /var/lib/app/${project_name}-prod/docker-compose.prod.yml up -d"
                            sh "ssh -p1412 -o StrictHostKeyChecking=no scio@${prod_deployment_instance} docker exec laravel-app chown -R www-data:www-data ."
                        }
                    }
                }
            }
        }

    }

    // Cleaning Jenkins workspace
    post {
        always {
            sh 'docker image prune -a -f' // remove built images
            emailext(body: content, mimeType: 'text/html',
            replyTo: '$DEFAULT_REPLYTO', subject: subject,
            to: 'dev@scio.systems', attachLog: true )
            cleanWs()
        }
    }
}
